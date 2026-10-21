<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

class TestPayPalCredentials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sonar:test:paypal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the PayPal credentials.';

    /**
     * Create a new command instance.
     *
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (config("customer_portal.paypal_enabled") !== true) {
            $this->error("PayPal is not enabled in the customer portal configuration.");
            return;
        }

        $client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init(
                    (string) config("customer_portal.paypal_api_client_id"),
                    (string) config("customer_portal.paypal_api_client_secret")
                )
            )
            //You can set this to Environment::SANDBOX if you want to test with a sandbox account
            ->environment(Environment::PRODUCTION)
            ->build();

        try {
            $client->getClientCredentialsAuth()->fetchToken();
        } catch (Exception $e) {
            $this->error("Credentials failed! Please make sure this is a LIVE account and not a SANDBOX account and try again.");
            $this->error("Specific error was: {$e->getMessage()}");
            return;
        }
        $this->info("Credentials tested successfully.");
    }
}
