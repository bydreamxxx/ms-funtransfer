<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Helper;
use App\Helpers\ProviderHelper;
use GuzzleHttp\Client;
use Carbon\Carbon;
use DB;


class BoomingGamingController extends Controller
{
    public $provider_db_id = 36; // no update databse insert provider

    public function __construct(){
    	$this->api_key = config('providerlinks.booming.api_key');
    	$this->api_secret = config('providerlinks.booming.api_secret');
    	$this->api_url = config('providerlinks.booming.api_url');
    }
    
    public function gameList(){
        $nonce = date('mdYhisu');
        $url =  $this->api_url.'/v2/games';
        $requesttosend = "";
        $sha256 =  hash('sha256', $requesttosend);
        $concat = '/v2/games'.$nonce.$sha256;
        $secrete = hash_hmac('sha512', $concat, $this->api_secret);

        
        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/vnd.api+json',
                'X-Bg-Api-Key' => $this->api_key,
                'X-Bg-Nonce'=> $nonce,
                'X-Bg-Signature' => $secrete
            ]
        ]);
       $guzzle_response = $client->get($url);
       $client_response = json_decode($guzzle_response->getBody()->getContents());
       return json_encode($client_response);
    }

    //THIS IS PART OF GAMELAUNCH GET SESSION AND URL
    public function callBack(Request $request){
        $header = [
            'bg_nonce' => $request->header('bg-nonce'),
            'bg_signature' => $request->header('bg-signature')
        ];
        Helper::saveLog('Booming Callback ', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $header);
        $data = $request->all();
        $client_details = ProviderHelper::getClientDetails('player_id',$data["player_id"]);
        if($client_details != null){
            try{
            $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
            $get_savelog = Helper::getGameCode($data["session_id"], $this->provider_db_id);
            $request_data = json_decode($get_savelog->request_data); // get request_data 
            $game_code = $request_data->game_code;
            $url = $request_data->url;
            $game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $game_code);
            $game_ext = Providerhelper::findGameExt($data['customer_id'], 2, 'transaction_id'); 
                if($game_ext == 'false'): // NO BET found mw
                    //if the amount is grater than to the bet amount  error message
                    if($player_details->playerdetailsresponse->balance < $data['bet']):
                        $errormessage = array(
                            'error' => '2006',
                            'message' => 'Invalid balance'
                            
                        );
                        Helper::saveLog('Booming Callback error ', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $errormessage);
                        return json_encode($errormessage, JSON_FORCE_OBJECT); 
                    endif;
                    $amount = $data["bet"] - $data["win"];
                    $transactiontype = $data["win"] == '0.0' ? 'debit' : 'credit';
                    $requesttosend = [
                        "access_token" => $client_details->client_access_token,
                        "hashkey" => md5($client_details->client_api_key.$client_details->client_access_token),
                        "type" => "fundtransferrequest",
                        "datesent" => Helper::datesent(),
                        "gamedetails" => [
                            "gameid" => $game_details->game_code, // $game_details->game_code
                            "gamename" => $game_details->game_name
                        ],
                        "fundtransferrequest" => [
                                "playerinfo" => [
                                "client_player_id" => $client_details->client_player_id,
                                "token" => $client_details->player_token,
                            ],
                            "fundinfo" => [
                                "gamesessionid" => "",
                                "transferid" => "",
                                "transactiontype" => $transactiontype,
                                "rollback" => "false",
                                "currencycode" => $client_details->default_currency,
                                "amount" => $amount
                            ]
                        ]
                        ];

                    try {
                        $client = new Client([
                            'headers' => [ 
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer '.$client_details->client_access_token
                            ]
                        ]);
                        $guzzle_response = $client->post($client_details->fund_transfer_url,
                            ['body' => json_encode($requesttosend)]
                        );

                        $client_response = json_decode($guzzle_response->getBody()->getContents());
                        $response =  [
                            "balance" => (string)$client_response->fundtransferresponse->balance,
                            "return" => $url,
                            'error' => 'reality_check',
                            'message' => 'Continue to the game.',
                            'buttons' => [
                                'title' => 'OK',
                                'action' => 'close_dialog',
                            ]
                        ];

                        $token_id = $client_details->token_id;
                        $bet_amount =  $data['bet'];
                        $payout = $data["win"];
                        $entry_id =  2; //1 bet , 2win
                        $win = $data["win"] == '0' ? 0 : 1;// 0 Lost, 1 win, 3 draw, 4 refund, 5 processing
                        
                        $income = $amount;
                        $provider_trans_id = $data['customer_id']; // this is customerid
                        $round_id = $data['round'];// this is round

                        $gametransaction_id = Helper::saveGame_transaction($token_id, $game_details->game_id, $bet_amount, $payout, $entry_id,  $win, null, null , $income, $provider_trans_id, $round_id);
                        
                        $provider_request = $data;
                        $mw_request = $requesttosend;
                        $mw_response = $response;
                        $client_response = $client_response;
                        $game_transaction_type = 2;

                        $this->creteBoomingtransaction($gametransaction_id, $provider_request,$mw_request,$mw_response,$client_response,$game_transaction_type, $bet_amount, $data['customer_id'], $data['round']);
                    
                        Helper::saveLog('Booming Callback Process ', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $response);
                        return json_encode($response, JSON_FORCE_OBJECT); 
                    }catch(\Exception $e){
                        $msg = array(
                            'error' => '2099',
                            'message' => $e->getMessage(),
                        );
                        Helper::saveLog('Booming Bet error', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $msg);
                        return json_encode($msg, JSON_FORCE_OBJECT); 
                    }
                else:
                    $errormessage = array(
                        'error' => '2099',
                        'message' => 'Generic validation error'
                    );
                    Helper::saveLog('Booming Callback error', $this->provider_db_id, json_encode($request->all(), JSON_FORCE_OBJECT), $errormessage);
                    return json_encode($errormessage, JSON_FORCE_OBJECT); 
                endif;
            }catch(\Exception $e){
                $msg = array(
                    'error' => '3001',
                    'message' => $e->getMessage(),
                );
                Helper::saveLog('Booming Callback error', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $msg);
                return json_encode($msg, JSON_FORCE_OBJECT); 
            }

		}else{
            $errormessage = array(
                'error' => '2012',
                'message' => 'Invalid Player ID'
            );
            Helper::saveLog('Booming Callback error', $this->provider_db_id, json_encode($request->all(), JSON_FORCE_OBJECT),  $errormessage);
            return json_encode($errormessage, JSON_FORCE_OBJECT); 
		}
        
    }

    public function rollBack(Request $request){
        $header = [
            'bg_nonce' => $request->header('bg-nonce'),
            'bg_signature' => $request->header('bg-signature')
        ];
        Helper::saveLog('Booming Rollback ', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $header);
        $data = $request->all();
        $client_details = ProviderHelper::getClientDetails('player_id',$data["player_id"]);
        // $existing_bet = ProviderHelper::findGameTransaction($data['customer_id'], 'transaction_id', 2); // Find if win has bet record
		$game_ext = ProviderHelper::findGameExt($data['customer_id'], 3, 'transaction_id'); // Find if this callback in game extension
        $get_savelog = Helper::getGameCode($data["session_id"], $this->provider_db_id);
        $request_data = json_decode($get_savelog->request_data); // get request_data 
        $game_code = $request_data->game_code;
        $url = $request_data->url;
        $game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $game_code);
        if($client_details != null):
            try{
                if($game_ext == 'false'):
                    $amount = $data["bet"] - $data["win"];
                    $transactiontype = $data["win"] == '0.0' ? 'debit' : 'credit';
                    $requesttosend = [
                            "access_token" => $client_details->client_access_token,
                            "hashkey" => md5($client_details->client_api_key.$client_details->client_access_token),
                            "type" => "fundtransferrequest",
                            "datesent" => Helper::datesent(),
                            "gamedetails" => [
                                "gameid" => $game_details->game_code, // $game_details->game_code
                                "gamename" => $game_details->game_name
                            ],
                            "fundtransferrequest" => [
                                "playerinfo" => [
                                "client_player_id" => $client_details->client_player_id,
                                "token" => $client_details->player_token
                            ],
                            "fundinfo" => [
                                    "gamesessionid" => "",
                                    "transferid" => "",
                                    "transactiontype" => 'credit',
                                    "rollback" => "true",
                                    "currencycode" => $client_details->default_currency,
                                    "amount" => $data['bet']
                            ]
                            ]
                    ];
                        try {
                            $client = new Client([
                                'headers' => [ 
                                    'Content-Type' => 'application/json',
                                    'Authorization' => 'Bearer '.$client_details->client_access_token
                                ]
                            ]);
                            $guzzle_response = $client->post($client_details->fund_transfer_url,
                                ['body' => json_encode($requesttosend)]
                            );
                            $client_response = json_decode($guzzle_response->getBody()->getContents());
                            
                            $response =  [
                                "balance" => (string)$client_response->fundtransferresponse->balance,
                                "return" => $url,
                                'error' => 'reality_check',
                                'message' => 'Continue to the game.',
                                'buttons' => [
                                    'title' => 'OK',
                                    'action' => 'close_dialog',
                                ]
                            ];
                            
                            $token_id = $client_details->token_id;
                            $bet_amount =  $data['bet'];
                            $payout = $data["win"];
                            $entry_id =  2; //1 bet , 2win
                            $win = 4;// 0 Lost, 1 win, 3 draw, 4 refund, 5 processing
                            
                            $income = $amount;
                            $provider_trans_id = $data['customer_id']; // this is customerid
                            $round_id = $data['round'];// this is round
    
                            $gametransaction_id = Helper::saveGame_transaction($token_id, $game_details->game_id, $bet_amount, $payout, $entry_id,  $win, null, null , $income, $provider_trans_id, $round_id);
                            
                            $provider_request = $data;
                            $mw_request = $requesttosend;
                            $mw_response = $response;
                            $client_response = $client_response;
                            $game_transaction_type = 3;
    
                            $this->creteBoomingtransaction($gametransaction_id, $provider_request,$mw_request,$mw_response,$client_response,$game_transaction_type, $bet_amount, $data['customer_id'], $data['round']);
                        
                            Helper::saveLog('Booming Callback Process ', $this->provider_db_id, json_encode($request->all(),JSON_FORCE_OBJECT), $response);
                            return json_encode($response, JSON_FORCE_OBJECT);
    
                        }catch(\Exception $e){
                            $errormessage = [
                                'error' => '2099',
                                'message' => $e->getMessage()
                            ];
                            Helper::saveLog('Booming Payout error', $this->provider_db_id,  json_encode($request->all(),JSON_FORCE_OBJECT), $errormessage);
                            return json_encode($errormessage, JSON_FORCE_OBJECT); 
                        }
                      
                else:
                        // NOTE IF CALLBACK WAS ALREADY PROCESS PROVIDER DONT NEED A ERROR RESPONSE! LEAVE IT AS IT IS!
                        $errormessage = [
                            'error' => '2010',
                            'message' => 'Unsupported parameters provided'
                        ];
                    Helper::saveLog('Booming Rollback error', $this->provider_db_id,  json_encode($request->all(),JSON_FORCE_OBJECT), $errormessage);
                    return json_encode($errormessage, JSON_FORCE_OBJECT); 
                endif;
            }catch(\Exception $e){
                $errormsg = [
                    'error' => '3001',
                    'message' => $e->getMessage()
                ];
                Helper::saveLog('Booming Rollback error', $this->provider_db_id, json_encode($request->all(), JSON_FORCE_OBJECT),  $errormsg);
                return json_encode($errormsg, JSON_FORCE_OBJECT); 
            }
        else:
            $errormsg = [
                'error' => '2012',
                'message' => 'Invalid Player ID'
            ];
            Helper::saveLog('Booming Rollback error', $this->provider_db_id, json_encode($request->all(), JSON_FORCE_OBJECT),  $errormsg);
            return json_encode($errormsg, JSON_FORCE_OBJECT); 
        endif;
        
    }

    public static function creteBoomingtransaction($gametransaction_id,$provider_request,$mw_request,$mw_response,$client_response, $game_transaction_type, $amount=null, $provider_trans_id=null, $round_id=null){
		$gametransactionext = array(
			"game_trans_id" => $gametransaction_id,
			"provider_trans_id" => $provider_trans_id,
			"round_id" => $round_id,
			"amount" => $amount,
			"game_transaction_type"=>$game_transaction_type,
			"provider_request" => json_encode($provider_request),
			"mw_request"=>json_encode($mw_request),
			"mw_response" =>json_encode($mw_response),
			"client_response" =>json_encode($client_response),
		);
		$gamestransaction_ext_ID = DB::table("game_transaction_ext")->insertGetId($gametransactionext);
		return $gametransactionext;
	}
}
