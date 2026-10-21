<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\PaymentSubmission;
use App\Events\PaymentSuccessfullySubmittedEvent;
use App\PaypalTemporaryToken;
use Exception;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletExperienceContextBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CaptureStatus;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\OrderStatus;
use PaypalServerSdkLib\Models\PaypalExperienceUserAction;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use RuntimeException;
use SonarSoftware\CustomerPortalFramework\Controllers\AccountBillingController;

class PayPalController extends Controller
{
    private $currency;
    private PaypalServerSdkClient $client;

    /**
     * PayPalController constructor.
     */
    public function __construct()
    {
        $this->currency = config("customer_portal.paypal_currency_code");

        $this->client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init(
                    (string) config("customer_portal.paypal_api_client_id"),
                    (string) config("customer_portal.paypal_api_client_secret")
                )
            )
            //You can set this to Environment::SANDBOX if you want to test with a sandbox account
            ->environment(Environment::PRODUCTION)
            ->build();
    }

    /**
     * Generate the approval link for a PayPal payment
     * @param $amountToPay
     * @return mixed
     */
    public function generateApprovalLink($amountToPay)
    {
        if (config("customer_portal.paypal_enabled") !== true) {
            throw new RuntimeException("PayPal is not enabled in the customer portal configuration.");
        }

        $amountToPay = number_format($amountToPay, 2, ".", "");

        $tempToken = new PaypalTemporaryToken(['account_id' => get_user()->accountId, 'token' => uniqid(true)]);
        $tempToken->save();

        $purchaseUnit = PurchaseUnitRequestBuilder::init(
                AmountWithBreakdownBuilder::init($this->currency, $amountToPay)->build()
            )
            ->description(utrans("billing.paymentToCompany", ['company_name' => config("customer_portal.company_name")]))
            ->invoiceId(uniqid(true)) //This is not a payment on a specific invoice, so we'll just generate a unique string
            ->build();

        $experienceContext = PaypalWalletExperienceContextBuilder::init()
            ->returnUrl(action("PayPalController@completePayment", ['temporary_token' => $tempToken->token]))
            ->cancelUrl(action("PayPalController@cancelPayment", ['temporary_token' => $tempToken->token]))
            ->userAction(PaypalExperienceUserAction::PAY_NOW)
            ->build();

        $orderRequest = OrderRequestBuilder::init(CheckoutPaymentIntent::CAPTURE, [$purchaseUnit])
            ->paymentSource(
                PaymentSourceBuilder::init()
                    ->paypal(PaypalWalletBuilder::init()->experienceContext($experienceContext)->build())
                    ->build()
            )
            ->build();

        $response = $this->client->getOrdersController()->createOrder(['body' => $orderRequest]);
        if (!$response->isSuccess()) {
            throw new RuntimeException("PayPal order creation failed with HTTP status {$response->getStatusCode()}.");
        }

        foreach ($response->getResult()->getLinks() ?? [] as $link) {
            if ($link->getRel() === 'payer-action') {
                return $link->getHref();
            }
        }

        throw new RuntimeException("PayPal order response did not include an approval link.");
    }

    /**
     * Complete the PayPal payment
     * @param Request $request
     * @param $temporaryToken
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function completePayment(Request $request, $temporaryToken)
    {
        $token = PaypalTemporaryToken::where('token', '=', $temporaryToken)->where('account_id', '=', get_user()->accountId)->first();
        if ($token === null) {
            $error = utrans("errors.paypalTokenInvalid");
            return view("pages.paypal.error", compact('error'));
        }
        $token->delete();

        // PayPal appends the order ID as "token" when redirecting back after approval.
        if ($request->input('token') === null || $request->input('PayerID') === null) {
            $error = utrans("errors.missingPaypalInformation");
            return view("pages.paypal.error", compact('error'));
        }

        try {
            $response = $this->client->getOrdersController()->captureOrder([
                'id' => $request->input('token'),
                'prefer' => 'return=representation',
            ]);
        } catch (Exception $e) {
            $error = utrans("errors.paypalGenericError");
            return view("pages.paypal.error", compact('error'));
        }

        $order = $response->isSuccess() ? $response->getResult() : null;
        $capture = $order?->getPurchaseUnits()[0]?->getPayments()?->getCaptures()[0] ?? null;

        if ($order?->getStatus() !== OrderStatus::COMPLETED || $capture?->getStatus() !== CaptureStatus::COMPLETED) {
            $error = utrans("errors.paymentNotApproved");
            return view("pages.paypal.error", compact('error'));
        }

        //POST the payment back into Sonar for storage
        try {
            $accountBillingController = new AccountBillingController();
            $amount = $capture->getAmount()->getValue();
            $accountBillingController->storePayPalPayment(get_user()->accountId, $amount, $capture->getId());

            event(new PaymentSuccessfullySubmittedEvent(new PaymentSubmission([
                'account' => session('account'),
                'amount' => \intval(round($amount * 100)),
            ])));
        } catch (Exception $e) {
            $error = utrans("errors.failedToApplyPaypalPayment");
            return view("pages.paypal.error", compact('error'));
        }

        $billingController = new BillingController;
        $billingController->clearBillingCache();
        return view("pages.paypal.success");
    }

    /**
     * This route is hit if the user cancels the payment from the PayPal site.
     * @param $temporaryToken
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function cancelPayment($temporaryToken)
    {
        $token = PaypalTemporaryToken::where('token', '=', $temporaryToken)->where('account_id', '=', get_user()->accountId)->first();
        if ($token !== null) {
            $token->delete();
        }

        return view("pages.paypal.cancel");
    }
}
