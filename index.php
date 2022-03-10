<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Management\SubscriptionTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use laravel\pagseguro\Platform\Laravel5\PagSeguro;
use laravel\pagseguro\Parser\Xml;

class PagseguroController extends Controller
{
    public static function notification($information)
    {
        foreach($information->getItems() as $item){
            $transaction = SubscriptionTransaction::find($item->getId());
            if($transaction){
                $transaction->fill([
                    'pagseguro_status_code' => $information->getStatus()->getCode(),
                    'pagseguro_status_name' => $information->getStatus()->getName(),
                    'pagseguro_code' => $information->getCode()
                ])->save();
            }
        }
        return response()->json(['msg' => 'OK'], 200);
    }

    public function webhookNotification(Request $request)
    {
        $credentials = PagSeguro::credentials()->get();


        $dados = $this->checkNotification($request->notificationCode, $credentials);
        $factoryBase = '\laravel\pagseguro\%s\Information\InformationFactory';
        $factoryClass = sprintf($factoryBase, ucfirst($request->notificationType));
        $factory = new $factoryClass($dados);
        $information = $factory->getInformation();
        $users = [];
        if($information){
            foreach($information->getItems() as $item){
                $transaction = SubscriptionTransaction::find($item->getId());
                if($transaction){
                array_push($users, $transaction->inscrito()->name);
                $transaction->fill([
                        'pagseguro_status_code' => $information->getStatus()->getCode(),
                        'pagseguro_status_name' => $information->getStatus()->getName(),
                        'pagseguro_code' => $information->getCode()
                    ])->save();
                }
            }
        }

        return response()->json(['Status' => 'Transação atualizada', 'users'=> $users], 201);
    }

    /**
     * Get Transaction Status
     * @param string $code
     * @param CredentialsInterface $credential
     * @return array Array with transaction info
     */
    public function checkNotification($code, $credential)
    {
        $sandbox= env('PAGSEGURO_SANDBOX')? '.sandbox.':'.';
        $completeUrl = "https://ws{$sandbox}pagseguro.uol.com.br/v3/transactions/notifications/{$code}?email=".env('PAGSEGURO_EMAIL')."&token=".env('PAGSEGURO_TOKEN');

        $curl = curl_init($completeUrl);
        curl_setopt($curl, CURLOPT_URL,$completeUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        //for debug only!
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $resp = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 200) {
            $error = 'Error on send: ' . $httpCode.' '.$resp. $completeUrl;
            throw new \RuntimeException($error);
        }

        $parser = new Xml($resp);
        return $parser->toArray();

    }
}
