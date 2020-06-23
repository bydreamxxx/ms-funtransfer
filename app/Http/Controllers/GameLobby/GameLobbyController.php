<?php

namespace App\Http\Controllers\GameLobby;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Game;
use App\Models\GameType;
use App\Models\GameProvider;
use App\Models\GameSubProvider;
use App\Helpers\Helper;
use App\Helpers\GameLobby;
use Stripe\Balance;
use DB;

class GameLobbyController extends Controller
{

    public $image_url = 'https://bo-test.betrnk.games/';
    //
    // public function __construct(){
	// 	$this->middleware('oauth', ['except' => ['index']]);
	// 	/*$this->middleware('authorize:' . __CLASS__, ['except' => ['index', 'store']]);*/
	// }
    public function getGameList(Request $request){
        if($request->has("type")){
            $type = GameType::with("game.provider")->get();
            return $type;
        }
        else{
            $providers = GameProvider::with("games.game_type")->get(["provider_id","provider_name", "icon"]);
            $data = array();
            foreach($providers as $provider){
                $providerdata = array(
                    "provider_id" => $provider->provider_id,
                    "provider_name" => $provider->provider_name,
                    "icon" => $this->image_url.$provider->icon,
                    "games_list" => array(),
                );
                foreach($provider->games as $game){
                    if($game->sub_provider_id == 0){
                        $game = array(
                            "game_id" => $game->game_id,
                            "game_name"=>$game->game_name,
                            "game_code"=>$game->game_code,
                            "game_type" => $game->game_type->game_type_name,
                            "subpro" =>$game->sub_provider_id,
                            "game_provider"=> $game->provider->provider_name,
                            "game_icon" => $game->icon,
                        );
                        array_push($providerdata["games_list"],$game);
                    }
                }
                array_push($data,$providerdata);
            }
            $sub_providers = GameSubProvider::with("games.game_type")->get(["sub_provider_id","sub_provider_name", "icon"]);
            foreach($sub_providers as $sub_provider){
                $subproviderdata = array(
                    "provider_id" => "sp".$sub_provider->sub_provider_id,
                    "provider_name" => $sub_provider->sub_provider_name,
                    "icon" => $this->image_url.$sub_provider->icon,
                    "games_list" => array(),
                );
                foreach($sub_provider->games as $game){
                    $game = array(
                        "game_id" => $game->game_id,
                        "game_name"=>$game->game_name,
                        "game_code"=>$game->game_code,
                        "game_type" => $game->game_type->game_type_name,
                        "game_provider"=> $game->provider->provider_name,
                        "game_icon" => $game->icon,
                    );
                    array_push($subproviderdata["games_list"],$game);
                }
                array_push($data,$subproviderdata);
            }
            return $data;
        }
        
    }
    public function gameLaunchUrl(Request $request){
        if($request->has('client_id')
        &&$request->has('client_player_id')
        &&$request->has('username')
        &&$request->has('email')
        &&$request->has('display_name')
        &&$request->has('game_code')
        &&$request->has('exitUrl')
        &&$request->has('game_provider')
        &&$request->has('token')){
            if($token=Helper::checkPlayerExist($request->client_id,$request->client_player_id,$request->username,$request->email,$request->display_name,$request->token,$request->game_code)){
                if($request->input('game_provider')=="Iconic Gaming"){
                    $msg = array(
                        "game_code" => $request->input("game_code"),
                        "url" => GameLobby::icgLaunchUrl($request->game_code,$token,$request->exitUrl),
                        "game_launch" => true
                    );
                    return response($msg,200)
                    ->header('Content-Type', 'application/json');
                }
                elseif($request->input('game_provider')=="Endorphina Gaming"){
                    $msg = array(
                        "game_code" => $request->input("game_code"),
                        "url" => GameLobby::edpLaunchUrl($request->game_code,$token,$request->exitUrl),
                        "game_launch" => true
                    );
                    return response($msg,200)
                    ->header('Content-Type', 'application/json');
                }
                 elseif($request->input('game_provider')=="Bole Gaming"){
                    $url = GameLobby::boleLaunchUrl($request->game_code,$token,$request->exitUrl,$request->country_code);
                    if($url){
                        $msg = array(
                            "game_code" => $request->input("game_code"),
                            "url" => $url,
                            "game_launch" => true
                        );
                    }else{
                        $msg = array(
                            "game_code" => $request->input("game_code"),
                            "game_launch" => false
                        );
                    }
                    return $msg;
                }
                elseif($request->input('game_provider')=="RSG Gaming"){ // request->token
                    Helper::saveLog('DEMO CALL', 11, json_encode($request->all()), 'DEMO');
                    $msg = array(
                        "game_code" => $request->input("game_code"),
                        "url" => GameLobby::rsgLaunchUrl($request->game_code,$request->token,$request->exitUrl), //TEST
                        "game_launch" => true
                    );
                    return response($msg,200)
                    ->header('Content-Type', 'application/json');
                }
                elseif($request->input('game_provider')=="IA Gaming"){ // request->token
                    // Helper::saveLog('DEMO CALL', 11, json_encode($request->all()), 'DEMO');
                    $msg = array(
                        "game_code" => $request->input("game_code"),
                        "url" => GameLobby::iaLaunchUrl($request->game_code,$request->token,$request->exitUrl), //TEST
                        "game_launch" => true
                    );
                    return response($msg,200)
                    ->header('Content-Type', 'application/json');
                }
                elseif($request->input('game_provider')=="Solid Gaming"){
                    $msg = array(
                        "game_code" => $request->input("game_code"),
                        "url" => GameLobby::solidLaunchUrl($request->game_code,$request->token,$request->exitUrl), 
                        "game_launch" => true
                    );
                    return response($msg,200)
                    ->header('Content-Type', 'application/json');
                }
            }
        }
        else{
            $msg = array(
                "error_code" => "INVALID_INPUT",
                "message" => "Missing Required Input"
            );
            return response($msg,200)
            ->header('Content-Type', 'application/json');
        }
    }
    public function getPlayerBalance(Request $request){
        if($request->has("token")){
            $player = GameLobby::getClientDetails("token",$request->token);
            $balance = DB::table("player_balance")->where("token_id",$player->token_id)->first();
            $gametransaction = DB::table("game_transactions")->select(DB::raw('SUM(bet_amount) as bet'),DB::raw('SUM(pay_amount) as win'))->where("token_id",$player->token_id)->first();
            $newbalance = (float)$balance->balance + (float)$gametransaction->win - (float)$gametransaction->bet;
            return $newbalance;
        }
        else{
            $msg = array(
                "error_code" => "TOKEN_INVALID",
                "message" => "Missing Required Input"
            );
            return response($msg,200)
            ->header('Content-Type', 'application/json');
        }
    }
    public function gameLobbyLaunchUrl(Request $request){
        $url = "https://daddy.betrnk.games/authenticate";
        if($request->has('client_id')
        &&$request->has('client_player_id')
        &&$request->has('username')
        &&$request->has('email')
        &&$request->has('display_name')
        &&$request->has('exitUrl')
        &&$request->has('token')){
            if($token=Helper::checkPlayerExist($request->client_id,$request->client_player_id,$request->username,$request->email,$request->display_name,$request->token)){
                $data = array(
                    "url" => $url."?token=".$token."&user_id=".$request->client_player_id."&email=".$request->email."&displayname=".$request->display_name."&username=".$request->username."&exiturl=".$request->exitUrl,
                    "launch" => true
                );
                return $data;
            }
        }
        return "Invalid Input";
    }
    // TEST SINGLE PROVIDER
    public function getProviderDetails(Request $request, $provider_name){
        $clean_url = urldecode($provider_name);
        $providers = GameProvider::where("provider_name", $clean_url)
                    ->get(["provider_id","provider_name", "icon"]);
 
            $data = array();
            foreach($providers as $provider){
                $providerdata = array(
                    "provider_id" => $provider->provider_id,
                    "provider_name" => $provider->provider_name,
                    "icon" => $this->image_url.$provider->icon,
                    "games_list" => array(),
                );
                foreach($provider->games as $game){
                    $game = array(
                        "game_id" => $game->game_id,
                        "game_name"=>$game->game_name,
                        "game_code"=>$game->game_code,
                        "game_type" => $game->game_type->game_type_name,
                        "game_provider"=> $game->provider->provider_name,
                        "game_icon" => $game->icon,
                    );
                    array_push($providerdata["games_list"],$game);
                }
                array_push($data,$providerdata);
            }
            return $data;
    }
}
