<?php

namespace App\Services;

use Exception;
use App\Models\Payment;
use App\Jobs\ActivatePlanJob;
use App\Interfaces\PaymentGateway;
use Illuminate\Support\Facades\Http;

class Korapay implements PaymentGateway
{

    private $baseUrl;
    private $accessToken;
    public function __construct()
    {
        $this->baseUrl = config('payment.korapay.base_url');
        $this->accessToken = config('payment.korapay.access_token');
    }

    public function processPayment($paymentData)
    {
        $response = $this->initialisePayment($paymentData);
        if ($response['status'] === true && !(empty($response['data']))) {
            $redirectUrl  = $response['data']['checkout_url'];
            return $redirectUrl;
        } else {
            return 'Failed to initailise payment successfyully';
        }
    }

    public function refundPayment($transactionId, $amount)
    {
    }

    private function initialisePayment(array $data)
    {
        $url = $this->baseUrl . 'charges/initialize';
        $reference = 'KORA_REF_' . uniqid();
        $body = [
            "amount" => $data['amount'],
            "redirect_url" => config('payment.korapay.redirect_url'),
            "currency" =>  "NGN",
            "reference" =>  $reference,
            "narration" => 'subscription',
            "channels" => [
                "card",
                "bank_transfer"
            ],
            "default_channel" => "card",
            "customer" => [
                "name" => auth()->user()->name,
                "email" => auth()->user()->email
            ],
        ];

        $payment =  Payment::create([
            'user_id' => auth()->user()->id,
            'reference' => $reference,
            'amount' => $data['amount'],
            'type' => $data['type'],
            'type_id' => $data['type_id'],
            'payment_gateway_name' => Payment::PAYMENT_TYPE_KORAPAY
        ]);

        return rescue(function () use ($data, $reference, $url, $body, $payment) {
            $response = Http::retry(3, 100)
                ->withToken($this->accessToken)
                ->post($url, $body);

            if ($response->successful()) {
                return $response->json();
            } else {
                $payment->update(['status' => Payment::FAILED]);
                info($response->status());
                throw new Exception("Failed to initialize payment. Status code: " . $response->status());
            }
        }, function ($ex) use ($payment) {
            $payment->update(['status' => Payment::FAILED]);
            throw new Exception("An error occurred while trying to initialize payment ");
        });
    }

    public function verifyPayment($payment){
        return rescue(function () use ($payment){
             $url =  $this->baseUrl. 'charges/'.$payment->reference;
             $response = Http::retry(3, 100)
                 ->withToken($this->accessToken)
                 ->get($url);
 
             if ($response->successful()) {
                 return $response->json();
             } else {
                 $payment->update(['status' => Payment::FAILED]);
                 info($response->status());
                 throw new Exception("Failed to initialize payment. Status code: " . $response->status());
             }
             },function(\Exception $ex)use($payment){
                $payment->update(['status' => Payment::FAILED]);
                throw new Exception("An error occurred while trying to initialize payment ");
             });
    }


}
