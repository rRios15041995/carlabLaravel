  <?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Service;
use Pusher\Laravel\Facades\Pusher;

class API extends Controller
{

  function testing(){
    $orders = DB::table('Order')->where('status', '=', 0)->get();

    if ( count($orders) > 0) {

      foreach ($orders as $order) {
        $c_o = $order->id;
        if ($order->rejections < 2) {
          $closest=DB::table('OrderCandidate')->where('order_id','=',$c_o)->min('service_distance');

          $worker = DB::table('OrderCandidate')->where('worker_response', '!=', 2)->where('order_id','=',$c_o)->where('service_distance', $closest)->first();

            Pusher::trigger('worker-'.$worker->worker_id, 'new-order', ['order' => $order]);
        }else{
            $message = "No hay operadores disponibles por el momento";
            Pusher::trigger('order-'.$order->id, 'no-workers', ['message' => $message] );
             /*Delete order from DB*/
            DB::table('Order')->where('id', $order->id)->delete();
            DB::table('OrderCandidate')->where('order_id', $order->id)->delete();
        }
      }
    }else {
      echo "No Pending Orders";
    }
  }

  //<!--[Report Location]-->//
  function findCandidates($worker_list, $order_id){

    $order = DB::table('Order')->where('id','=',$order_id)->first();

    foreach ($worker_list as $worker) {
      $total_distance = $this->getServiceDistance($order->latitude, $order->longitude, $worker->latitude, $worker->longitude, $earthRadius = 6371000);

      if ($total_distance < 1650) {

        if(DB::table('OrderCandidate')->where('worker_id', '=', $worker->fireID)->exists()){
          return response()->json(['response' => "User Exists"]);
        }else{
          $candidate = DB::table('OrderCandidate')->insertGetId([
            'worker_id' => $worker->fireID,
            'order_id' => $order_id,
            'service_distance' => $total_distance,
            'order_status' => $order->status
          ]);
        }
        Pusher::trigger("worker-".$worker->fireID, "on-queue", ['ticket' => $candidate]);

          return "OK";
      }

    }
    return "OK";
  }

  //<!--[Update Location]-->//
  function updateLocation(Request $req){
    $data = $req->all();


      DB::table('Worker')->where('fireID', $data['worker_id'])->update(['latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
      return response()->json(['answer' => 'OK']);

  }

  //<!--[Get All Services]-->//
  function services(Request $req){

    $services = DB::select('select * from Service');

    return response()->json(['services' => $services]);
  }

  //<!--[Get Service ==> Categories ==> SubCategories]-->//
  function get_categories(Request $req, $service_id){

    // return $service_id;
    $subcategories = [];

    $categories = (DB::table('Category')->where('service_id', $service_id)->get());

    foreach ($categories as $category) {
      if ($category->sub_cat != 0) {
        $category->sub_category = $this->getSubCategories($category->id);
      }
    }

    return response()->json(['categories' => $categories]);

  }

  //<!--[Create New Worker]-->//
  function createWorker(Request $req){

    $data = $req->all();

    $worker = DB::table('Worker')->insert([

      'last_name' => $data['last_name'],
      'status' => '0',
      'fireID' => $data['fireID'],
      'email' => $data['email'],
      'phone' => $data['phone'],
      'name' => $data['name'],
      'role' => $data['role']

    ]);

    return response()->json(['status' => '200']);

  }

  //<!--[Get Worker & Orders]-->//
  function getWorker(Request $req){

    $worker = DB::table('Worker')->where('fireID', $req->fireID)->first();

    if(count($worker)>0){
      $worker_id = $worker->fireID;
      $worker_role = $worker->role;

      $orders = $this->getWorkerOrders($worker_id, $worker_role);

      return response()->json(['worker' => $worker, 'orders' => $orders]);
    }else{


      return response()->json(['status'=> '0']);

    }
  }

  //<!--[Get User]-->//
  function getUser(Request $req, $fireID){
    $user = DB::table('User')->where('fireID', $req->fireID)->first();
    $card = DB::table('UserBilling')->where('user_id', $req->fireID)->first();
    $car  = DB::table('Cars')->where('user_id', $req->fireID)->first();
    $user_id = $user->fireID;

    // $orders = getWorkerOrders($worker_id, $worker_role);

    return response()->json(['user' => $user, 'car' => $car, 'card' => $card]);

  }

  //<!--[Change Worker Status]-->//
  function workerStatus(Request $req, $fireID){
    $worker = DB::table('Worker')->where('fireID', $fireID)->first();

    if ($worker->status != '0') {
      DB::table('Worker')->where('fireID', $fireID)->update(['status' => '0']);
    }else {
      DB::table('Worker')->where('fireID', $fireID)->update(['status' => '1']);
    }

    $nworker = DB::table('Worker')->where('fireID', $fireID)->first();
    return response()->json(['data' => $nworker->status]);
  }

  //<!--[Change Worker (LogOut)]-->//
  function workerLogOut(Request $req, $fireID){
    $worker = DB::table('Worker')->where('fireID', $fireID)->first();

    if ($worker->status != '0') {
      DB::table('Worker')->where('fireID', $fireID)->update(['status' => '0']);
      $nworker = DB::table('Worker')->where('fireID', $fireID)->first();
      return response()->json(['data' => $nworker->status]);
    }else {
      $nworker = DB::table('Worker')->where('fireID', $fireID)->first();
      return response()->json(['data' => $nworker->status]);
    }

  }

  //<!--[Create New User]-->//
  function createFB(Request $req){
    $data = $req->all();

    if (count(DB::table('User')->where('fireID', $data['fireID'])->first())>0) {

      return response()->json(['data' => "OK", 'code' => "0"]);
    }else {
      $user = DB::table('User')->insert([
        'last_name' => $data['last_name'],
        'fireID' => $data['fireID'],
        'email' => $data['email'],
        'name' => $data['first_name'],
      ]);


      return response()->json(['data' => "OK", 'code' => "0"]);
  }


  }

  //<!--[Create New User]-->//
  function createUser(Request $req){
    $data = $req->all();

    $worker = DB::table('User')->insert([
      'last_name' => $data['last_name'],
      'fireID' => $data['fireID'],
      'email' => $data['email'],
      'name' => $data['name'],
      'phone' => $data['name']
    ]);

    return response()->json(['data' => "OK", 'status' => "200"]);

  }

  //<!--[Change User Data]-->//
  function updateUser(Request $req, $fireID){

    $data = $req->all();
    $user = DB::table('User')->where('fireID',$fireID)->update(['email'=> $data['email'],
    'phone' => $data['phone']]);
    $card = DB::table('UserBilling')->where('user_id', $req->fireID)->first();
    $car  = DB::table('Cars')->where('user_id', $req->fireID)->first();

    if (count($card)>0) {
      DB::table('UserBilling')->where('user_id',$fireID)->update(['card_number' => $data['card']]);
    }else{
      DB::table('UserBilling')->insert([
        'user_id' => $fireID,
        'card_number'=> $data['card']
      ]);
    }

    if (count($car)>0) {
      DB::table('Cars')->where('user_id',$fireID)->update(['car_model' => $data['car']]);
    }else{
      DB::table('Cars')->insert([
        'user_id' => $fireID,
        'car_model'=> $data['car']
      ]);
    }

    return response()->json(['status' => '200']);
  }

  //<!--[Change Worker Status]-->//
  function updateWorker(Request $req, $fireID){

    $data = $req->all();

    $worker = DB::table('Worker')->where('fireID',$fireID)->update(['email'=> $data['email'],
    'phone' => $data['phone']]);

    return response()->json(['status' => '200']);
  }

  //<!--[Create Order]-->//
  function createOrder(Request $req){
    $request = $req->all();
    $data = $request['order'];
    $token = $request['token_object'];
    $worker_list = $this->findWorker($data['service_name']);

    if ( count($worker_list)>0) {

      if($token['token'] == "money"){

        if($data['has_sub']!= "true"){
          //Register unasigned Order.
          $order_id = DB::table('Order')->insertGetId([
            'status' => $data['status'],
            'latitude' => $data['lat'],
            'longitude' => $data['lng'],
            'ammount' => $data['ammount'],
            'car_plate' => $data['car_plate'],
            'user_id' => $data['user'],
            'service_name' => $data['service_name'],
            'details' => $data['details'],
            'has_sub' => 'false',
            'service_date' => $data['date'],
            'category_id' => $data['category_id'],
            'token' => "money"
          ]);
        }else{
          //Register unasigned Order + SubCat.
          $order_id = DB::table('Order')->insertGetId([
            'status' => $data['status'],
            'latitude' => $data['lat'],
            'longitude' => $data['lng'],
            'ammount' => $data['ammount'],
            'car_plate' => $data['car_plate'],
            'user_id' => $data['user'],
            'service_name' => $data['service_name'],
            'details' => $data['details'],
            'service_date' => $data['date'],
            'category_id' => $data['category_id'],
            'has_sub' => 'true',
            'subcat_name' => $data['subcat_name'],
            'subcat_id' => $data['subcat_id'],
            'token' => "money"
          ]);
        }
      }else{
        if($data['has_sub']!= "true"){
          //Register unasigned Order.
          $order_id = DB::table('Order')->insertGetId([
            'status' => $data['status'],
            'latitude' => $data['lat'],
            'longitude' => $data['lng'],
            'ammount' => $data['ammount'],
            'car_plate' => $data['car_plate'],
            'user_id' => $data['user'],
            'service_name' => $data['service_name'],
            'details' => $data['details'],
            'service_date' => $data['date'],
            'has_sub' => 'false',
            'category_id' => $data['category_id'],
            'token' => $token['id']
          ]);
        }else{
          //Register unasigned Order + SubCat.
          $order_id = DB::table('Order')->insertGetId([
            'status' => $data['status'],
            'latitude' => $data['lat'],
            'longitude' => $data['lng'],
            'ammount' => $data['ammount'],
            'car_plate' => $data['car_plate'],
            'user_id' => $data['user'],
            'service_name' => $data['service_name'],
            'details' => $data['details'],
            'service_date' => $data['date'],
            'category_id' => $data['category_id'],
            'has_sub' => 'true',
            'subcat_name' => $data['subcat_name'],
            'subcat_id' => $data['subcat_id'],
            'token' => $token['id']
          ]);
        }
      }

      $this->findCandidates($worker_list, $order_id);


      return response()->json(['status' => '200', 'order_id' => $order_id, 'workers' => $worker_list, 'count' => count($worker_list)]);

    }else{

      return response()->json(['status' => '200', 'order_id' => "0", 'workers' => "no_workers"]);

    }

  }

  //<!--[Reject Order]-->//
  function rejectOrder(Request $req, $order_id, $fireID){

    $candidate = DB::table('OrderCandidate')->where('worker_id', $fireID)->where('order_id',$order_id)->update(['worker_response' => 2]);
    DB::table('Order')->where('id', $order_id)->increment('rejections');
    DB::table('OrderCandidate')->where('worker_id', $fireID)->delete();


    return response()->json(['status' => '200']);

  }

  //<!--[Challenge Order]-->//
  function challengeOrder(Request $req, $order_id, $fireID){
    require_once(app_path()."/conekta-php/lib/Conekta.php");
    \Conekta\Conekta::setApiKey("key_7czD6sAx2ooMpfGxBdcpBw");
    // \Conekta\Conekta::setApiKey("key_nqHcxy7u15yQ7D1mKJXqmw");
    \Conekta\Conekta::setApiVersion("2.0.0");
    $data = $req->all();

    $order = DB::table('Order')->where('id', $order_id)->first();
    $worker = DB::table('Worker')->where('fireID', $fireID)->first();


    if ($order->status != 0) {

      return response()->json(['code' => '2']);

    }else{

      if($order->token == 'money'){

        DB::table('Order')->where('id', $order_id)->update(['worker_id'=> $fireID,
        'status' => 1]);
        DB::table('OrderCandidate')->where('order_id', $order_id)->delete();;

        Pusher::trigger('order-'.$order->id, 'got-worker', ['order' => $order]);

        return response()->json(['code' => '1']);

      }else{


        $user = DB::table('User')->where('fireID', '=', $order->user_id)->first();

        //Create Customer Conekta
        try {
          $customer = \Conekta\Customer::create(
            array(
              "name" => $user->name." ".$user->last_name,
              "email"=> $user->email,
              "phone"=> $user->phone,
              "payment_sources"=> array(
                array(
                  "type" => "card",
                  "token_id" => $order->token
                )//Payment Sources
              )//Card Data
            )//Customer Array
          );//Conekta Customer
        } catch (\Conekta\ProccessingError $error){

          Pusher::trigger('order-'.$order->id, 'info-error', ['error' => $error, 'order' => $order] );
          DB::table('Order')->where('id', '=', $order->id)->delete();
          return response()->json(['code' => '2']);
        } catch (\Conekta\ParameterValidationError $error){

          Pusher::trigger('order-'.$order->id, 'info-error', ['error' => $error, 'order' => $order] );
          DB::table('Order')->where('id', '=', $order->id)->delete();
          return response()->json(['code' => '2']);
        } catch (\Conekta\Handler $error){

          Pusher::trigger('order-'.$order->id, 'info-error', ['error' => $error, 'order' => $order] );
          DB::table('Order')->where('id', '=', $order->id)->delete();
          return response()->json(['code' => '2']);
        }

        if ($order->has_sub == "true") {
          $subcategory = DB::table('SubCategory')->where('id', '=', $order->subcat_id)->first();
          $category = DB::table('Category')->where('id', '=', $order->category_id)->first();

          try{
            $order = \Conekta\Order::create(
              array(
                "line_items" => array(
                  array(
                    "name" => $order->service_name." ".$category->name,
                    "unit_price" => intval($category->price)*100,
                    "quantity" => 1
                  ),
                  array(
                    "name" => $subcategory->name,
                    "unit_price" => intval($subcategory->price)*100,
                    "quantity" => 1
                  )
                ), //line_items
                "currency" => "MXN",
                "customer_info" => array(
                  "customer_id" => $customer['id']
                ), //customer_info
                "charges" => array(
                  array(
                      'payment_method' => array(
                      'type' => 'default'
                    ) //first charge
                  ) //charges
                )//order
              )
            );
            DB::table('Order')->where('id', $order_id)->update(['worker_id'=> $fireID,
            'status' => 1]);
            DB::table('OrderCandidate')->where('order_id', $oorder_id)->delete();;
            Pusher::trigger('order-'.$order->id, 'got-worker', ['order' => $order]);
            return response()->json(['code' => '1']);

          } catch (\Conekta\ParameterValidationError $error){
          DB::table('Order')->where('id', $order_id)->delete();
            Pusher::trigger('order-'.$order->id, 'payment-error', ['error' => $error, 'customer'=> $customer]);
            return response()->json(['code' => '2']);
          } catch (\Conekta\Handler $error){
          DB::table('Order')->where('id', $order_id)->delete();
            Pusher::trigger('order-'.$order->id, 'payment-error', ['error' => $error, 'customer'=> $customer]);
            return response()->json(['code' => '2']);
          }

        }else {
          $category = DB::table('Category')->where('id', '=', $order->category_id)->first();

          try{
            $conekta_order = \Conekta\Order::create(
              array(
                "line_items" => array(
                  array(
                    "name" => $order->service_name." ".$category->name,
                    "unit_price" => intval($category->price)*100,
                    "quantity" => 1
                  )
                ), //line_items
                "currency" => "MXN",
                "customer_info" => array(
                  "customer_id" => $customer['id']
                ), //customer_info
                "charges" => array(
                  array(
                      'payment_method' => array(
                      'type' => 'default'
                    ) //first charge
                  ) //charges
                )//order
              )
            );

            DB::table('Order')->where('id', $order_id)->update(['worker_id'=> $fireID,
            'status' => 1]);
            DB::table('OrderCandidate')->where('order_id', $oorder_id)->delete();;

            Pusher::trigger('order-'.$order->id, 'got-worker', ['order' => $order]);

            return response()->json(['code' => '1']);

          } catch (\Conekta\Handler $error){
          DB::table('Order')->where('id', $order_id)->delete();
            Pusher::trigger('order-'.$order->id, 'payment-error', ['error' => $error, 'customer'=> $customer]);
            return response()->json(['code' => '2']);
          } catch (\Conekta\ProccessingError $error){
          DB::table('Order')->where('id', $order_id)->delete();
            Pusher::trigger('order-'.$order->id, 'payment-error', ['error' => $error, 'customer'=> $customer]);
            return response()->json(['code' => '2']);
          } catch (\Conekta\ParameterValidationError $error){
          DB::table('Order')->where('id', $order_id)->delete();
            Pusher::trigger('order-'.$order->id, 'payment-error', ['error' => $error, 'customer'=> $customer]);
            return response()->json(['code' => '2']);
          }

        }
      }


    }



  }

  //<!--[Fetch Orders]-->//
  function getOrders(Request $req, $fireID){
    $orders = DB::table('Order')->where('user_id', $fireID)->where('status','=', 4)->get();
    if (count($orders)>0) {
      return response()->json(['orders' => $orders, 'code' => "200"]);
    }else{
      return response()->json(['orders' => $orders, 'code' => "0"]);
    }
  }

  //<!--[Fetch Orders]-->//
  function getActives(Request $req, $fireID){
    $orders = DB::table('Order')->where('user_id', $fireID)->where('status','!=', 4)->get();
    if (count($orders)>0) {
      return response()->json(['orders' => $orders, 'code' => "200"]);
    }else{
      return response()->json(['orders' => $orders, 'code' => "0"]);
    }
  }

  //<!--[Terminate Order]-->//
  function terminateOrder(Request $req, $order_id, $now){
    DB::table('Order')->where('id', $order_id)->update(['status'=> "4", 'end_date' => $now]);
    Pusher::trigger('order-'.$order_id, 'order-done', ['order_id' => $order_id]);
    return response()->json(['result' => "ok", 'code' => "200"]);
  }

  /**te and end order**/
  function evaluateOrder(Request $req, $order_id){
    $data = $req->all();
    $order = DB::table('Order')->where('id', $order_id)->first();
    if ($data['rating'] >= 5) {
      DB::table('Order')->where('id', $order_id)->update(['comments'=> $data['comments'], 'rating' => 5.0]);

      DB::table('Worker')->where('fireID', $order->worker_id)->increment('stars');
      DB::table('Worker')->where('fireID', $order->worker_id)->increment('on_time');

    }else{
      DB::table('Order')->where('id', $order_id)->update(['comments'=> $data['comments'], 'rating' => floatval($data['rating'])]);
    }

    $avg = $this->evaluateWorker($order->worker_id);
    $this->waterSaver($order->user_id);

    return response()->json(['result' => "ok", 'code' => "200", 'avg' => $avg]);
  }

  //<!--[Start Order]-->//
  function startOrder(Request $req, $order_id, $now){
    DB::table('Order')->where('id', $order_id)->update(['status'=> "2", 'starting_date' => $now]);
    Pusher::trigger('order-'.$order_id, 'route-started', ['message' => "Operador en camino"]);
    return response()->json(['result' => "ok", 'code' => "200"]);
  }


  function subcat_order(Request $req, $order_id, $now){
    DB::table('Order')->where('id', $order_id)->update(['status'=> "6", 'subcat_date' => $now]);
    Pusher::trigger('order-'.$order_id, 'subcat-started', ['message' => "Servicio Iniciado"]);
    return response()->json(['result' => "ok", 'code' => "200"]);
  }


  //<!--[Wash Order]-->//
  function startWash(Request $req, $order_id, $now){
    DB::table('Order')->where('id', $order_id)->update(['status'=> "3", 'cleaning_date' => $now]);


    Pusher::trigger('order-'.$order_id, 'wash-started', ['message' => "Lavado iniciado"]);
    return response()->json(['result' => "ok", 'code' => "200"]);
  }


  function checkEmail(Request $req, $fireID){

  }


  /*Custom Reusable Functions<------------------------------------------------------->*/


  function getServiceDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
  {
    // convert from degrees to radians
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
      cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
  }
  // TO-DO
  function tryAssign(){
    $orders = DB::table('Order')->where('status', '=', 0)->get();

    if ( count($orders) > 0) {

      foreach ($orders as $order) {
        $c_o = $order->id;
        // if (DB::table('OrderCandidate')->where('order_id','=',$c_o)->where('worker_response','=',0)->count() < 3) {

          // $worker_first = DB::table('OrderCandidate')->where('order_id','=',$c_o)->where('worker_response','!=',0)->min('service_distance')->first();
          //
          // Pusher::trigger('worker-'.$worker_first->worker_id, "new-order", ['order'=> $order]);
          // return "OK";

          return DB::table('OrderCandidate')->where('order_id','=',$c_o)->first();
        // }
      }
    }else {
      echo "No Pending Orders";
      return "OK";
    }

  }
  // #<!-- Fetch Orders By Worker ID -->
  function getWorkerOrders($worker_id, $worker_role){

    $orders = DB::table('Order')->where('worker_id', $worker_id)->where('service_name', $worker_role)->where('status', '<', 4)->orWhere('status', '=', 6)->get();

    return $orders;
  }

  // #<!-- Fetch Orders By ID -->
  function getOrderDetails($order_id){

    $order = DB::table('Order')->where('id', $order_id)->first();

    return $order;
  }

  // #<!-- Fetch Workers By Status -->
  function findWorker($order_data){

    $worker_list = DB::table('Worker')->where('status', 1)->where('role', $order_data)->get();
    return $worker_list;

  }

  //<!--[Fetch SubCategory By Category ID]-->//
  function getSubCategories($category_id){

    $sub_cat = DB::table('SubCategory')->where('category_id', $category_id)->first();

    return $sub_cat;
  }

  /*Evaluate and modify worker rating*/
  function evaluateWorker($worker_id){
    $new_average = DB::table('Order')->where('worker_id', $worker_id)->avg('rating');

    DB::table('Worker')->where('fireID', $worker_id)->update(['rating' => $new_average]);
    return $new_average;
  }

  /*Sum carwash service*/
  function waterSaver($user_id){
    DB::table('User')->where('fireID', '=', $user_id)->increment('services');
    return "OK";
  }
  #<!------------------------------------------------------------------------------>

  function getTerms(){
    return view('terms');
  }
}
