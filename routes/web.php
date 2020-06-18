<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/
// Home page
$app->get('/', function () use ($app) {
    return $app->version();
});
// Posts
$app->get('/posts','PostController@index');
$app->post('/posts','PostController@store');
$app->get('/posts/{post_id}','PostController@show');
$app->put('/posts/{post_id}', 'PostController@update');
$app->patch('/posts/{post_id}', 'PostController@update');
$app->delete('/posts/{post_id}', 'PostController@destroy');
// Users
$app->get('/users/', 'UserController@index');
$app->post('/users/', 'UserController@store');
$app->get('/users/{user_id}', 'UserController@show');
$app->put('/users/{user_id}', 'UserController@update');
$app->patch('/users/{user_id}', 'UserController@update');
$app->delete('/users/{user_id}', 'UserController@destroy');
// Comments
$app->get('/comments', 'CommentController@index');
$app->get('/comments/{comment_id}', 'CommentController@show');
// Comment(s) of a post
$app->get('/posts/{post_id}/comments', 'PostCommentController@index');
$app->post('/posts/{post_id}/comments', 'PostCommentController@store');
$app->put('/posts/{post_id}/comments/{comment_id}', 'PostCommentController@update');
$app->patch('/posts/{post_id}/comments/{comment_id}', 'PostCommentController@update');
$app->delete('/posts/{post_id}/comments/{comment_id}', 'PostCommentController@destroy');

// Player Details Request
$app->post('/api/playerdetailsrequest/', 'PlayerDetailsController@show');

// Fund Transfer Request
$app->post('/api/fundtransferrequest/', 'FundTransferController@process');

// Solid Gaming Endpoints
$app->post('/api/solid/{brand_code}/authenticate', 'SolidGamingController@authPlayer');
$app->post('/api/solid/{brand_code}/playerdetails', 'SolidGamingController@getPlayerDetails');
$app->post('/api/solid/{brand_code}/balance', 'SolidGamingController@getBalance');
$app->post('/api/solid/{brand_code}/debit', 'SolidGamingController@debitProcess');
$app->post('/api/solid/{brand_code}/credit', 'SolidGamingController@creditProcess');
$app->post('/api/solid/{brand_code}/debitandcredit', 'SolidGamingController@debitAndCreditProcess');
$app->post('/api/solid/{brand_code}/rollback', 'SolidGamingController@rollbackTransaction');
$app->post('/api/solid/{brand_code}/endround', 'SolidGamingController@endPlayerRound');
$app->post('/api/solid/{brand_code}/endsession', 'SolidGamingController@endPlayerSession');

// Oryx Gaming Endpoints
$app->post('/api/oryx/{brand_code}/tokens/{token}/authenticate', 'OryxGamingController@authPlayer');
$app->post('/api/oryx/{brand_code}/players/{player_id}/balance', 'OryxGamingController@getBalance');
$app->post('/api/oryx/{brand_code}/game-transaction', 'OryxGamingController@gameTransaction');

// SimplePlay Endpoints
$app->post('/api/simpleplay/{brand_code}/GetUserBalance', 'SimplePlayController@getBalance');
$app->post('/api/simpleplay/{brand_code}/PlaceBet', 'SimplePlayController@debitProcess');
$app->post('/api/simpleplay/{brand_code}/PlayerWin', 'SimplePlayController@creditProcess');
$app->post('/api/simpleplay/{brand_code}/PlayerLost', 'SimplePlayController@lostTransaction');
$app->post('/api/simpleplay/{brand_code}/PlaceBetCancel', 'SimplePlayController@rollBackTransaction');

// ICG Gaming Endpoints
$app->get('/api/icgaming/gamelist','ICGController@getGameList');
$app->post('/api/icgaming/gamelaunch','ICGController@gameLaunchURL');
$app->get('/api/icgaming/authplayer','ICGController@authPlayer');
$app->get('/api/icgaming/playerDetails','ICGController@playerDetails');
$app->post('/api/icgaming/bet','ICGController@betGame');
$app->delete('/api/icgaming/bet','ICGController@cancelBetGame');
$app->post('/api/icgaming/win','ICGController@winGame');
$app->post('api/icgaming/withdraw','ICGController@withdraw');
$app->post('api/icgaming/deposit','ICGController@deposit');
// EDP Gaming Endpoints
$app->post('/api/edp/gamelunch','EDPController@gameLaunchUrl');
$app->get('/api/edp/check','EDPController@index');
$app->get('/api/edp/session','EDPController@playerSession');
$app->get('/api/edp/balance','EDPController@getBalance');
$app->post('/api/edp/bet','EDPController@betGame');
$app->post('/api/edp/win','EDPController@winGame');
$app->post('/api/edp/refund','EDPController@refundGame');
$app->post('/api/edp/endSession','EDPController@endGameSession');
// Lottery Gaming Endpoints
$app->post('/api/lottery/authenticate', 'LotteryController@authPlayer');
$app->post('/api/lottery/balance', 'LotteryController@getBalance'); #/
$app->post('/api/lottery/debit', 'LotteryController@debitProcess'); #/
// $app->post('/api/lottery/credit', 'LotteryController@creditProcess');
// $app->post('/api/lottery/debitandcredit', 'LotteryController@debitAndCreditProcess');
// $app->post('/api/lottery/endsession', 'LotteryController@endPlayerSession');
// Mariott Gaming Endpoints
$app->post('/api/marriott/authenticate', 'MarriottController@authPlayer');
$app->post('/api/marriott/balance', 'MarriottController@getBalance'); #/
$app->post('/api/marriott/debit', 'MarriottController@debitProcess'); #/
// RGS Gaming Endpoints
$app->post('rsg/authenticate', 'DigitainController@authenticate');
$app->post('rsg/creategamesession', 'DigitainController@createGameSession');
$app->post('rsg/getbalance', 'DigitainController@getbalance');
$app->post('rsg/refreshtoken', 'DigitainController@refreshtoken');
$app->post('rsg/bet', 'DigitainController@bet');
$app->post('rsg/win', 'DigitainController@win');
$app->post('rsg/betwin', 'DigitainController@betwin');
$app->post('rsg/refund', 'DigitainController@refund');
$app->post('rsg/amend', 'DigitainController@amend');
// IA SPORTS
$app->post('ia/hash', 'IAESportsController@hashen');
$app->post('ia/lunch', 'IAESportsController@userlunch');
$app->post('ia/register', 'IAESportsController@userRegister');
$app->post('ia/userwithdraw', 'IAESportsController@userWithdraw');
$app->post('ia/userdeposit', 'IAESportsController@userDeposit');
$app->post('ia/userbalance', 'IAESportsController@userBalance');
$app->post('ia/wager', 'IAESportsController@userWager');
$app->post('ia/hotgames', 'IAESportsController@getHotGames');
$app->post('ia/orders', 'IAESportsController@userOrders');
$app->post('ia/activity_logs', 'IAESportsController@userActivityLog');
$app->post('ia/deposit', 'IAESportsController@seamlessDeposit');
$app->post('ia/withdrawal', 'IAESportsController@seamlessWithdrawal');
$app->post('ia/balance', 'IAESportsController@seamlessBalance');
$app->post('ia/searchorder', 'IAESportsController@seamlessSearchOrder');
// Bole Gaming Endpoints
$app->post('/api/bole/register', 'BoleGamingController@playerRegister');
$app->post('/api/bole/logout', 'BoleGamingController@playerLogout');
$app->post('/api/bole/wallet/player/cost', 'BoleGamingController@playerWalletCost');
$app->post('/api/bole/wallet/player/balance', 'BoleGamingController@playerWalletBalance');
// EPOINT CONTROLLER
// $app->post('/api/epoint', 'EpointController@epointAuth'); #/
// $app->post('/api/epoint/bitgo', 'EpointController@bitgo'); #/
// EBANCO
// $app->post('/api/ebancobanks', 'EbancoController@getAvailableBank'); #/
// $app->post('/api/ebancodeposit', 'EbancoController@deposit'); #/
// $app->post('/api/ebancodeposithistory', 'EbancoController@deposithistory'); #/
// $app->post('/api/ebancodepositinfobyid', 'EbancoController@depositinfo'); #/
// $app->post('/api/ebancodepositinfobyselectedid', 'EbancoController@depositinfobyselectedid'); #/
// $app->post('/api/ebancodepositreceipt', 'EbancoController@depositReceipt'); #/
$app->post('/api/ebancoauth', 'EbancoController@connectTo'); 
$app->post('/api/ebancogetbanklist', 'EbancoController@getBankList'); 
$app->post('/api/ebancodeposit', 'EbancoController@makeDeposit'); 
$app->post('/api/ebancosenddepositreceipt', 'EbancoController@sendReceipt'); 
$app->post('/api/ebancodeposittransaction', 'EbancoController@depositInfo'); 
$app->post('/api/ebancodeposittransactions', 'EbancoController@depositHistory'); 
$app->post('/api/ebancoupdatedeposit', 'EbancoController@updateDeposit'); 
$app->post('/api/ebancotest','EbancoController@testrequest');

// Request an access token
$app->post('/oauth/access_token', function() use ($app){
    return response()->json($app->make('oauth2-server.authorizer')->issueAccessToken());
});
//paymentgateway routes
$app->get('/paymentgateways','Payments\PaymentGatewayController@index');
$app->post('/payment','Payments\PaymentGatewayController@paymentPortal');
$app->get('/coinpaymentscurrencies','Payments\PaymentGatewayController@getCoinspaymentRate');
$app->get('/currencyconversion','CurrencyController@currency');
$app->post('/updatetransaction','Payments\PaymentGatewayController@updatetransaction');
$app->post('/updatepayouttransaction','Payments\PaymentGatewayController@updatePayoutTransaction');
$app->post('qaicash/depositmethods','Payments\PaymentGatewayController@getQAICASHDepositMethod');
$app->post('qaicash/deposit','Payments\PaymentGatewayController@makeDepositQAICASH');
$app->post('qaicash/payoutmethods','Payments\PaymentGatewayController@getQAICASHPayoutMethod');
$app->post('qaicash/payout','Payments\PaymentGatewayController@makePayoutQAICASH');
$app->post('qaicash/payout/approve','Payments\PaymentGatewayController@approvedPayoutQAICASH');
$app->post('qaicash/payout/reject','Payments\PaymentGatewayController@rejectPayoutQAICASH');
///CoinsPayment Controller
///new payment gateways api
$app->post('payment/launchurl','Payments\PaymentLobbyController@paymentLobbyLaunchUrl');
$app->post('payout/launchurl','Payments\PaymentLobbyController@payoutLobbyLaunchUrl');
$app->post('payment/portal','Payments\PaymentLobbyController@payment');
$app->post('payout/portal','Payments\PaymentLobbyController@payout');
$app->post('payment/tokencheck','Payments\PaymentLobbyController@checkTokenExist');
$app->get('payment/list','Payments\PaymentLobbyController@getPaymentMethod');
$app->get('payout/list','Payments\PaymentLobbyController@getPayoutMethod');
$app->post('payment/check','Payments\PaymentLobbyController@minMaxAmountChecker');
$app->post('payment/paymongoupdate','Payments\PaymentLobbyController@paymongoUpdateTransaction');

$app->post('payment/transaction','Payments\PaymentLobbyController@getPayTransactionDetails');
$app->post('payment/cancel','Payments\PaymentLobbyController@cancelPayTransaction');
//GameLobby
$app->get('game/list','GameLobby\GameLobbyController@getGameList');
$app->get('game/provider/{provider_name}','GameLobby\GameLobbyController@getProviderDetails');
$app->post('game/launchurl','GameLobby\GameLobbyController@gameLaunchUrl');
$app->post('gamelobby/launchurl','GameLobby\GameLobbyController@gameLobbyLaunchUrl');
$app->get('game/balance','GameLobby\GameLobbyController@getPlayerBalance');
$app->post('game/addfavorite','GameLobby\GameFavoriteController@index');
$app->post('game/playerinfo','GameLobby\GameFavoriteController@playerInfo');
$app->post('game/playerfavoritelist','GameLobby\GameFavoriteController@playerFavorite');
$app->get('game/newestgames','GameLobby\GameInfoController@getNewestGames');
$app->get('game/mostplayed','GameLobby\GameInfoController@getMostPlayed');
$app->post('game/suggestions','GameLobby\GameInfoController@getGameSuggestions');
$app->get('game/topcharts','GameLobby\GameInfoController@getTopGames');
$app->get('game/topcharts/numberone','GameLobby\GameInfoController@getTopProvider');
$app->post('game/betlist','GameLobby\GameInfoController@getBetList');
$app->post('game/query','GameLobby\QueryController@queryData');

// IWallet
// $app->post('api/iwallet/makedeposit','IWalletController@makeDeposit');
$app->post('api/iwallet/makesettlement','IWalletController@makeSettlement');
// $app->post('api/iwallet/makepayment','IWalletController@makePayment');
$app->post('api/iwallet/makeremittance','IWalletController@makeRemittance');
