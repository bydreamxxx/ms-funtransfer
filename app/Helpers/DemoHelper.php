<?php
namespace App\Helpers;

use App\Helpers\GameLobby;
use App\Helpers\DemoController;
use App\Helpers\ProviderHelper;
use GuzzleHttp\Client;
use DB;

class DemoHelper{
    
    public static function DemoGame($json_data){

        $data = json_decode(json_encode($json_data));

        $game_details = DemoHelper::findGameDetails($data->game_provider, $data->game_code);
        if($game_details == false){
            $msg = array(
                "game_code" => $data->game_code,
                "url" => config('providerlinks.play_betrnk') . '/tigergames/api?msg=No Game Found',
                "game_launch" => false
            );
            return $msg;
        }

        $provider_id = GameLobby::checkAndGetProviderId($data->game_provider);
        $provider_code = $provider_id->sub_provider_id;
        
        if($provider_code == 33){ // Bole Gaming
            $response = array(
                "game_code" => $data->game_code,
                "url" => DemoHelper::getStaticUrl($data->game_code, $data->game_provider),
                "game_launch" => true
            );
        }
        elseif(in_array($provider_code, [39, 78, 79, 80, 81, 82, 83])){
            $msg = array(
                "game_code" => $data->game_code,
                "url" => DemoHelper::oryxLaunchUrl($data->game_code), 
                "game_launch" => true
            );
            return response($msg,200)
            ->header('Content-Type', 'application/json');
        }
        elseif($provider_code == 60){ // ygg drasil direct
            $response = array(
                "game_code" => $data->game_code,
                "url" => DemoHelper::yggDrasil($data->game_code),
                "game_launch" => true
            );
        }
        elseif($provider_code == 55){ // pgsoft
            $response = array(
                "game_code" => $data->game_code,
                "url" => DemoHelper::pgSoft($data->game_code),
                "game_launch" => true
            );
        }
        elseif($provider_code == 40){  // Evoplay
            $response = array(
                "game_code" => $data->game_code,
                "url" => DemoHelper::evoplay($data->game_code),
                "game_launch" => true
            );
        }
        elseif($provider_code == 75){  // KAGaming
            $response = array(
                "game_code" => $data->game_code,
                "url" => DemoHelper::kagaming($data->game_code),
                "game_launch" => true
            );
        }
        // elseif($provider_code == 34){ // EDP
        //     // / $client = new Client();
        //     // $guzzle_response = $client->get('https://edemo.endorphina.com/api/link/accountId/1002 /hash/' . md5("endorphina_4OfAKing@ENDORPHINA") . '/returnURL/' . $returnURL);
        //     // $guzzle_response = $client->get('http://edemo.endorphina.com/api/link/accountId/1002/hash/' . md5("endorphina2_SugarGliderDice@ENDORPHINA"));
        //     // $guzzle_response = $client->get('https://edemo.endorphina.com/api/link/accountId/1002/hash/5bb33a3ec5107d46fe5b02d77ba674d6');
        //     // $demoLink = file_get_contents('https://edemo.endorphina.com/api/link/accountId/1002/hash/5bb33a3ec5107d46fe5b02d77ba674d6');
        //     // return json_encode($demoLink);

        //     $game_code = $json_data['game_code'];
        //     $game_name = explode('_', $game_code);
        //     $game_code = explode('@', $game_name[1]);
        //     $game_gg = $game_code[0];
        //     $arr = preg_replace("([A-Z])", " $0", $game_gg);
        //     $arr = explode(" ", trim($arr));
        //     if (count($arr) == 1) {
        //         $url = 'https://endorphina.com/games/' . strtolower($arr[0]) . '/play';
        //     } else {
        //         $url = 'https://endorphina.com/games/' . strtolower($arr[0]) . '-' . strtolower($arr[1]) . '/play';
        //     }
        //     $msg = array(
        //         "game_code" => $json_data['game_code'],
        //         "url" => $url,
        //         "game_launch" => true
        //     );
        //     return response($msg, 200)
        //     ->header('Content-Type', 'application/json');
        // }
        else{
            $response = array(
                "game_code" => $data->game_code,
                "url" => config('providerlinks.play_betrnk') . '/tigergames/api?msg=No Demo Available',
                "game_launch" => false
            );
        }

        return $response;     
    }


    # Providers That Has Static URL DEMO LINK IN THE DATABASE
    public static function getStaticUrl($game_code, $game_provider){
        $game_demo = DB::table('games as g')
        ->select('g.game_demo')
        ->leftJoin('providers as p', "g.provider_id", "=", "p.provider_id")
        ->where('g.game_code', $game_code)
        ->where('p.provider_name', $game_provider)
        ->first();
        return $game_demo->game_demo;
    }

    public static function findGameDetails($game_provider, $game_code) {

        $provider_id = GameLobby::checkAndGetProviderId($game_provider);
        $provider_code = $provider_id->sub_provider_id;

        $game_details = DB::table("games as g")
            ->leftJoin("providers as p","g.provider_id","=","p.provider_id");
      
        $game_details->where([
            ["g.sub_provider_id", "=", $provider_code],
            ["g.game_code",'=', $game_code],
        ]);
        
        $result= $game_details->first();
        return $result ? $result : false;
	}
    // public static function oryxLaunchUrl($game_code,$token,$exitUrl){
    //     $url = $exitUrl;
    //     $url = config("providerlinks.oryx.GAME_URL").$game_code.'/open?token='.$token.'&languageCode=ENG&playMode=FUN';
    //     return $url;
    // }

    public static function oryxLaunchUrl($game_code){
        $url = config("providerlinks.oryx.GAME_URL").$game_code.'/open?languageCode=ENG&playMode=FUN';
        return $url;
    }

    public static function yggDrasil($game_code){
        return 'https://static-pff-tw.248ka.com/init/launchClient.html?gameid='.$game_code.'&lang=en&currency=USD&org='.config('providerlinks.ygg.Org').'&channel=pc';
    }

    public static function pgSoft($game_code){
        $operator_token = config('providerlinks.pgsoft.operator_token');
        $url = "https://m.pg-redirect.net/".$game_code."/index.html?language=en-us&bet_type=2&operator_token=".urlencode($operator_token);
        return $url;
    }

    public static function kagaming($game_code){
        $url = '' . config('providerlinks.kagaming.gamelaunch') . '/?g=' . $game_code . '&p=' . config('providerlinks.kagaming.partner_name') . '&u=1&t=RiANDRAFT&da=charity&cr=USD&loc=en&m=1&tl=GUIOGUIO' . '&ak=' . config('providerlinks.kagaming.access_key') . '';
        return $url;
    }

    public static function evoplay($game_code){
        $requesttosend = [
          "project" => config('providerlinks.evoplay.project_id'),
          "version" => 1,
          "token" => 'demo',
          "game" => $game_code, //game_code, game_id
          "settings" =>  [
            'language'=>'en',
            'https' => true,
          ],
          "denomination" => '1', // game to be launched with values like 1.0, 1, default
          "currency" => 'USD',
          "return_url_info" => true, // url link
          "callback_version" => 2, // POST CALLBACK
        ];
        $signature =  ProviderHelper::getSignature($requesttosend, config('providerlinks.evoplay.secretkey'));
        $requesttosend['signature'] = $signature;
        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ]);
        $response = $client->post(config('providerlinks.evoplay.api_url').'/game/geturl',[
            'form_params' => $requesttosend,
        ]);
        $res = json_decode($response->getBody(),TRUE);
        return isset($res['data']['link']) ? $res['data']['link'] : false;
    }
}