<?php

// Require any libraries needed for the cron to function.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/init.php';

use \WHMCS\Module\Addon\Moneybird\Models\Log;
use \WHMCS\Module\Addon\Moneybird\Models\Invoice;
use \WHMCS\Module\Addon\Moneybird\Models\Links\InvoiceLink;

// Do not allow direct calls
if (php_sapi_name() != "cli") {
  die('Unsupported');
}

// Set time limit to 5 minutes, we shouldn't really need longer
set_time_limit(300);

/**
 * Hook: synchronise invoices with Moneybird
 *
 * @param none
 * @return none
 */
function syncInvoiceInBatch($params = array(), $debug = false) {
  $module_settings = getMoneybirdSettings();

  // Do not run syncInvoiceInBatch if EnableCron is disabled
  if ($module_settings['EnableCron'] != 'on') {
    if ($debug) {
      print("Cron disabled\r\n");
    }

    return;
  }

  $moneybird = createMoneybirdConnection();

  // Loop through all invoices
  // TODO: Improve so InvoiceLink can be used, which would make this
  // loop much more efficient.
  $invoices = Invoice::where('tblinvoices.id', '>=', (int) $module_settings['InvoiceSyncStart']);
  $invoices = $invoices->where(function ($query) {
      $query = $query->where('tblinvoices.status', '=', 'Paid');
      $query = $query->orWhere('tblinvoices.status', '=', 'Unpaid');
      return $query;
  });

  // Do not include 0.00 invoices, perhaps make this optional in the future
  $invoices = $invoices->where('tblinvoices.total', '!=', '0.00');

  $invoice_sync_count = 0;
  foreach ($invoices->get() as $invoice) {
    try {
      // Save the invoice in Moneybird
      $moneybird_invoice = $invoice->createMoneybirdInvoice($moneybird);

      // Throttle invoice synchronisation so we're not overloading the API
      if ($moneybird_invoice != null) {
        $invoice_sync_count++;
      }

      if ($invoice_sync_count >= (int) $module_settings['InvoiceSyncThrottle']) {
        if ($debug) {
          print("Throttling limit hit.\r\n");
        }

        break;
      }
    } catch (\Exception $e) {
      // Log invoice exception and break the loop
      $log = new Log;
      $log->whmcs_id = $invoice->id;
      $log->status = 1;
      $log->type = 'invoice';
      $log->message = $e->getMessage();
      $log->save();
      break;
    }
  }

  if ($debug) {
    print("${invoice_sync_count} invoice(s) processed.\r\n");
  }
}

/**
 * Hook: synchronise financial mutations with WHMCS
 *
 * @param none
 * @return none
 */
function processFinancialMutations($params = array(), $debug = false) {
  $module_settings = getMoneybirdSettings();

  // Do not run syncInvoiceInBatch if EnableCron is disabled
  if ($module_settings['EnableCron'] != 'on') {
    if ($debug) {
      print("Cron disabled\r\n");
    }

    return;
  }

  // Set the period filter--order matters as modify changes the object
  $date = new DateTime();
  $current_month = $date->format('Ym');
  $last_month = $date->modify('-1 month')->format('Ym');

  // Get all transactions from Moneybird
  $moneybird = createMoneybirdConnection();
  $versions = $moneybird->FinancialMutation()->listVersions([
    'period' => sprintf("%s..%s",
      $last_month,
      $current_month
    ),
    'mutation_type' => 'debit',
  ]);

  $ids = [];
  foreach ($versions as $version) {
    $ids[] = $version->id;
  }

  // Limit the id's to the top 100
  arsort($ids);
  $ids = array_slice($ids, 0, 100);

  // Get the last mutations
  $mutations = $moneybird->FinancialMutation()->getVersions($ids);

  // Set sync count to 0
  $transaction_sync_count = 0;

  foreach ($mutations as $mutation) {
    $payments = $mutation->payments;

    if (!empty($payments)) {
      foreach ($payments as $payment) {
        // Cast as an object
        $payment = (object) $payment;

        // Only process SalesInvoice transactions
        if ($payment->invoice_type !== 'SalesInvoice') {
          continue;
        }

        // Try to get the WHMCS invoice id from our InvoiceLink model--this
        // saves more than a handful API calls
        $reference = 0;

        try {
          $invoice_link = InvoiceLink::where('moneybird_id', '=', $payment->invoice_id);

          // If we didn't find a link, get the invoice from Moneybird
          if ($invoice_link->count() == 0) {
            $invoice = $moneybird->salesInvoice()->find((int) $payment->invoice_id);
            $reference = $invoice->reference;

            // Update the link so we don't have to do this again
            $link = InvoiceLink::find($reference);
            if ($link == true) {
              $link->moneybird_id = $payment->invoice_id;
              $link->save();
            }
          }

          // If we did get the link, get the WHMCS invoice id
          if ($invoice_link->count() == 1) {
            $link = $invoice_link->first();
            $reference = $link->whmcs_invoice_id;
          }

        } catch (\Exception $e) {
          if ($debug) {
            print("{$payment->invoice_id} could not be found\r\n");
          }

          continue;
        }

        $invoice = Invoice::find($reference);
        if ($invoice == false) {
          if ($debug) {
            print("{$reference} - Does not exist in WHMCS\r\n");
          }

          continue;
        }

        if ($invoice->addPaymentFromMoneybird((object) $payment, $moneybird) != null) {
          $transaction_sync_count++;
        }
      }
    }
  }

  if ($debug) {
    print("{$transaction_sync_count} transaction(s) processed.\r\n");
  }
}


// Example of how to synchronise a single invoice
function syncInvoice($params) {
  $moneybird = createMoneybirdConnection();
  $invoice = Invoice::find(10985);
  $invoice->createMoneybirdInvoice($moneybird);
}

// Run jobs, enable debug mode
syncInvoiceInBatch(array(), true);
processFinancialMutations(array(), true);
