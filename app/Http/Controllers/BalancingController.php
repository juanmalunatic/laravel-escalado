<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Classes\Queue;
use App\Classes\ServerType;

class BalancingController extends Controller
{
    //[VARS]
    private $client;
    private $config;
    private $queues;
    private $servers;
    private $serverTypes;
    private $providers;
    private $consumersRequest;
    private $instancesSuggested;
    private $flag = false;
    private $laps = 0;
    private $flagReport = false;

    private $consInitial;
    private $consGoalInitial;
    private $consGoalIterative;
    private $consumersCurrent;
    private $srvTypeInstances;

    public function __construct() {
        $this->queues = [];
        $this->serverTypes = [];
        $this->consGoalIterative = [];
        $this->providersExcluded = [];

        $this->client = new \GuzzleHttp\Client([
            'auth' => ['guest', 'guest']
        ]); 
    }
    public function index()
    {
        $this->loadBalancingSettings();
        $this->loadServerTypes(); // Carga tipos de servidor y cuantos consumers tienen sus instancias
        $this->loadQueues();      // al leer las instancias, se miden las queues

        /*dump($this->config);
        dump($this->queues);
        dump($this->serverTypes); die();*/

        //$this->balanceWithRatiosProgressive();
        $this->balanceWithRatiosOD(); //Optimal Descent, la carne del algoritmo.
    }

    private function useSimulatedQueues(){

        $simulated_queues = [
            "allianz" => 2,
            "bolivar" => 1,
            "liberty" => 2
        ];

        foreach( $this->queues as $queue ){

            $queue->setMessages(
                "ready",
                $simulated_queues[ $queue->getName() ]
            );

            $queue->setMessages(
                "total",
                $simulated_queues[ $queue->getName() ]
            );
        }
    }


    // ------------------------------------------------------------------------
    // [ Configuración y carga inicial ]
    // ------------------------------------------------------------------------

    private function loadBalancingSettings()
    {

        $string = file_get_contents( config_path() . "/load_balancing/BalancingConfig.json");
        $balancing_config = json_decode($string, true);

        foreach( $balancing_config["providers"] as $key => $data){
            $this->providers[] = $key;
        }
        $this->config = $balancing_config;
    }

    private function loadQueues()
    {
        // Listar las queues remotas disponibles
        $res = $this->client->request("GET", "http://localhost:15672/api/queues/%2F/");
        $queues_data = json_decode( $res->getBody()->getContents() );

        // Instanciar un Queue por cada objeto remoto con información actualizada
        foreach ( $queues_data as $queue_info ) {
            if( $queue_info->name == "agm-services-sura" ) continue; //TO-DO remover!!!
            $this->queues[]  = new Queue( $queue_info );
        }   
    }

    private function loadServerTypes()
    {
        $string = file_get_contents( config_path() . "/load_balancing/ServerTypesConfig.json");
        $servers_config = json_decode($string, true);

        foreach( $servers_config as $server_info ) {
            $this->serverTypes[] = $this->instanciateServer( $server_info );
        }
    }

    private function instanciateServer( $server_data )
    {
        $server_info = $this->autofillMissingProviders( $server_data );
        $new_server = new ServerType( $server_info );
        return $new_server;
    }

    // ------------------------------------------------------------------------
    // [ Métodos de balanceo ]
    // ------------------------------------------------------------------------

    //[START]
    private function balanceWithRatiosOD()
    {  
        // Inicialización del algoritmo
        $this->useSimulatedQueues(); // Simular colas. Comentar para usar reales de RabbitMQ
        
        $this->fillConsInitial();      // Trae los consumers que existen en el sistema
        $this->fillConsGoalInitial();  // Calcula los consumers necesarios respetando el máximo y los ratios de conversion

        //echo "Configuración general: <br />";  dump($this->config);
        echo "ServerTypes e instancias: <br />"; dump($this->serverTypes);
        echo "Colas de RabbitMQ: <br />";        dump($this->queues);
        echo "Consumers iniciales: <br />";      dump($this->consInitial);
        echo "Consumers meta inicial: <br />";   dump($this->consGoalInitial);
        echo "<br /><br />";

        $this->consGoalIterative = $this->consGoalInitial;

        // --------- A PARTIR DE ACA ES ITERABLE -------------

        // consumersCurrent

        // Actualiza los cons que tiene el sistema
        $this->consIterative = $this->getCurrentConsumers(); //echo "Consumers actuales: "; dump($this->consIterative);
        
        // Se coteja la lista de exclusión con las metas iterativas.
        // Asi se asegura no quedar en un bucle infinito (cuando nadie puede atender el mayor numero de consumers necesarios)
        $cons_goals_filtered = self::getFilteredGoals(
            $this->consGoalIterative,
            $this->providersExcluded
        );

        // Seleccionar la primera meta a alcanzar
        // - Mayor a menor
        //   - Si iguales, desempatar alfabético
        $cons_priority = self::getPriorityConsumers( $cons_goals_filtered ); // echo '$cons_priority'; dump($cons_priority);
        echo "Consumidores prioritarios: " . reset($cons_priority) . " de ". key($cons_priority) ."<br />";

        // Revisa el menor numero de instancias necesario para cumplir las metas
        // - Si dos servertypes tienen un num de instancias iguales, desempata por "sabores" (tipos de aseguradoras q contiene)
        $best_match = $this->getBestServerTypeMatchForConsumers(
            key  ($cons_priority), // $provider
            reset($cons_priority)  // $num_consumers
        );

        // Si es imposible cumplir los consumidores pedidos por algun proveedor ...
        if( is_null($best_match["name"]) ){ 
            echo "No se puede cumplir con los " . reset($cons_priority) . " cons pedidos por " . key($cons_priority) ."<br />";
            echo "Se excluye ".key($cons_priority)." de las próximas iteraciones <br />";
            // Se excluye el proveedor de las siguientes iteraciones
            $this->providersExcluded[] = key($cons_priority); //$provider
            // break; // TO-DO romper el bucle
        } else {
            echo "La mejor config para los consumidores pedidos es: " . $best_match["instances"] . " instancia(s) del servtype " . $best_match["name"] ."<br />";
            // TO-DO aplicar la configuracion, actualizando instancias en el objeto ServerType respectivo
        }
    }

    // Multiplicar instancias por config para obtener el numero total de consumers de esta iteracion
    // OUT: [ "allianz" => 2, "bolivar" => 3, ... ]
    private function getCurrentConsumers()
    {
        $curr_consumers = [];
        foreach( $this->serverTypes as $srvtype ) {
            foreach( $srvtype->getConsumers() as $provider => $cons_nr ){
                if( ! isset($curr_consumers[$provider]) ) {
                    $curr_consumers[$provider] = 0;
                }
                $curr_consumers[$provider] += $srvtype->getInstances() * $cons_nr;
            }
        }
        return $curr_consumers;
    }

    // IN:  $goals_requested  = [ "allianz" => 0, "bolivar" => 0, "sura" => 2 ... ];
    //      $goals_to_exclude = ["allianz", "sura"];
    // OUT: remueve "allianz" -> [["bolivar"=>0], ... ]
    private static function getFilteredGoals( $goals_requested, $goals_to_exclude )
    {
        $final_goals = $goals_requested;
        foreach($goals_to_exclude as $provider){
            unset( $final_goals[$provider] );
        }
        return $final_goals;
    }

    // Elige el proveedor que solicite más consumidores.
    // Si dos o más solicitan los mismos, desempate alfabético.
    // IN:   [ ["allianz"=>3] , ["bolivar"] => 1 ]
    // OUT:  formato [ "allianz" => 3 ]
    private static function getPriorityConsumers ($array_in) : array
    {
        $input_arr  = $array_in;
        $target = "";
        arsort($input_arr); // Sortear
        $values_freq   = array_count_values($input_arr);
        $times_largest = reset($values_freq); // Revisar cuantas veces se repite el mayor nr

        if( $times_largest > 1 ){ // Desempata de acuerdo a orden alfabético
            $mayores = [];
            foreach( $input_arr as $key => $value){
                if($value == key($values_freq)){ // Key($values_freq) tiene el mayor nr
                    $mayores[$key] = $value;
                }
            }
            ksort($mayores); //Primero alfabéticamente
            $target = [
                key($mayores) => reset($mayores)
            ];
        } else {
            $target = [
                key($input_arr) => reset($input_arr)
            ];
        }
        return $target;
    }

    // Retorna [
    //      name      => "type-x" / null
    //      instances => integer  / null
    // ]
    private function getBestServerTypeMatchForConsumers ($provider, $cons_requested) : array
    {
        $running_match = [
            "name"      => null,
            "instances" => null
        ];
        $best_matches = [];

        //Se iteran los tipos de servidor para buscar el menor numero de intancias para cumplir con los consumers
        foreach ($this->serverTypes as $serverType) {

            $cons_per_instance = $serverType->getConsumers($provider);
            if( $cons_per_instance <= 0 ) continue;

            $instances_needed = (int)ceil($cons_requested / $cons_per_instance);
            $current_attempt = [
                "instances" => $instances_needed,
                "name"      => $serverType->getName(),
            ];

            // echo "ServerType ". $serverType->getName() . " nita " . $instances_needed . " <br/>"; dump($best_matches);

            // Se revisa si crea menos instancias que lo anteriormente guardado (si es mejor)
            if (
                ( is_null($running_match["instances"]) ) ||           // Esta iteración es mejor
                ( $running_match["instances"] > $instances_needed )
            ) {
                $running_match  = $current_attempt;
                $best_matches   = []; // Vaciamos lo anterior
                $best_matches[] = $current_attempt;

            } else if (                 
                ( $running_match["instances"] == $instances_needed )  // Esta iteración es igual a la mejor
            ) {
                $best_matches[] = $current_attempt;
            }
        }
    
        if( count($best_matches) > 1 ) {
            
            // Formatear en lista de providers ["type-1", "type-2",...]
            $best_matches_arr = [];
            foreach( $best_matches as $val) {
                $best_matches_arr[] = $val["name"];
            }
            // Buscar el tipo de servidor que tenga mas "sabores" (diferentes aseguradoras)
            $best_match_str = $this->searchServerTypesFindMostProviders($best_matches_arr);
            return [
                "name"      => $best_match_str[0],
                "instances" => $best_matches[0]["instances"],
            ];
        } else {
            // Permite devolver null si no habia servidores que atendieran
            return $running_match;  
        }
    }

    // In:  ["type-1","type-2"]
    // Out: ["type-1"]
    private function searchServerTypesFindMostProviders( $srvtypes_list ) : array
    {   
        //echo "Se debe desambiguar entre dos proveedores: "; dump($srvtypes_list);
        $best_match = null;
        foreach($srvtypes_list as $srvtype_name){
            $srvtype = $this->getServerByName($srvtype_name);
            if( 
                ( is_null($best_match) ) ||
                ($best_match >  $srvtype->getProviderCount()) 
            ) {
                $best_match = $srvtype->getName();
            }
        }

        if( is_null($best_match) ){
            throw \Exception("No se pudo desambiguar entre dos servidores óptimos.");
        }
        echo "El servidor que tiene más proveedores (elegido) es " . $best_match . "<br />";
        return [$best_match];
    }

    // ------------------------------------------------------------------------
    // [ Métodos útiles ]
    // ------------------------------------------------------------------------

    // Llena información faltante en matriz de config:
    // Ej: Si un servidor solo tiene "bolivar":1, esta funcion completa
    // "allianz":0, "sura":0, etc.
    private function autofillMissingProviders( $server_arr )
    {    
        foreach( $this->config["providers"] as $provider_name => $data ) {
            if( ! array_key_exists( $provider_name , $server_arr["consumers"] )){
                $server_arr["consumers"][$provider_name] = 0;
            }
        }
        return $server_arr;
    }

    private function fillconsInitial(){
        foreach( $this->getInitialConsumers() as $proveedor => $consumers_nr ){
            $this->consInitial[$proveedor] = $consumers_nr;
        }
    }

    // Retorna $array = [
    //    "proveedor_name" => consumers_num
    // ]
    private function getInitialConsumers ()
    {
        // [Para simulación:]
        // Instancias_srvtype * Config_srvtype
        $consumers = [];
        foreach( $this->serverTypes as $server_type ){
            foreach( $server_type->getConsumers() as $provider => $consumers_nr ) {
                if( ! array_key_exists($provider, $consumers) ){
                    $consumers[$provider] = 0;
                }
                $consumers[$provider] += $consumers_nr * $server_type->getInstances(); 
            }
        }
        foreach( $this->queues as $queue ) {
            $queue->setConsumers( $consumers[ $queue->getName() ] );
        }

        //--------------------------------------------------------------------   
        //  Usar nº consumers leidos desde RabbitMQ
        //--------------------------------------------------------------------
        // TO-DO Esto se debe usar en deploy, sin lo de arriba.
        // TO-DO revisar consistencia entre num de consumers:
        // · Instancias (reportadas por gCloud) * ServerType.config_consumers
        // · Reportados por RabbitMQ

        foreach( $this->queues as $queue ){
            $consumers[ $queue->getName() ] = $queue->getConsumers();
        }
        return $consumers;
    }

    private function getUnprocessedMessages()
    {
        $messages = [];
        foreach( $this->queues as $queue ){
            $messages[ $queue->getName() ] = $queue->getMessages("ready");
        }
        return $messages;
    }

    private function getProviderData ($field)
    {
        $provider_data = [];
        foreach( $this->config["providers"] as $provider => $data ) {
            $provider_data[ $provider ] = $data[ $field ];
        }
        return $provider_data;
    }

    private function getRequestTimes()
    {
        return $this->getProviderData("request_time");
    }


    private function getMaxConsumers( $provider = null )
    {
        if( is_null($provider) ){
            return $this->getProviderData("max_consumers");
        } else {
            return $this->getProviderData("max_consumers")[$provider];
        }
    }

    private function getWorkerMessageRatios()
    {
        // Simulamos la lectura de un JSON
        $ret = [
            "allianz" => 1,
            "bolivar" => 1,
            "liberty" => 1
        ];
        return $ret;
    }

    private function getServerByName( $name ){
        foreach( $this->serverTypes as $server ){
            if( $server->getName() === $name ){
                return $server;
            }
        }
    }

    private function getLargestArrAssoc( $array_assoc ){
        arsort($array_assoc);
        reset($array_assoc);
        $first_item_key = key($array_assoc);

        $retarr = [
            $first_item_key => $array_assoc[ $first_item_key]
        ];

        return $retarr;
    }

    private function getAvaliableConsumersOld()
    {
        // Contar el numero de consumers disponibles
        $consumers = [];
        foreach( $this->serverTypes as $server ){
            foreach( $server->getConsumers() as $resource => $consumers_nr ) {
                if( ! array_key_exists($resource, $consumers) ){
                    $consumers[$resource] = 0;
                }
                $consumers[$resource] += $consumers_nr * $server->getInstances(); 
            }
        }
        return $consumers;
    }

    private function fillConsGoalInitial()
    {

        // Para cada Queue:
        // Calcula el número de consumers que se deben crear de acuerdo al ratio de consumers/messages
        // Luego, trunca ese numero de consumers de acuerdo a los límites máximos creados
        $messages   = $this->getUnprocessedMessages(); //echo "Msgs pendientes: "; dump($messages);
        $ratios     = $this->getWorkerMessageRatios(); //echo "Ratios: <br />"   ; dump($ratios);

        echo "Estimando meta de consumidores: <br />";

        foreach( $messages as $provider => $msgs_total ) { // Iterar por cada Queue

            echo "<hr> <h3>" . $provider . "</h3>";

            $consumers  = $this->consInitial[$provider];  dump("Consumers: " . $consumers);
            $msgs_ready = $messages[$provider];                dump("Msjs: " . $msgs_ready);
            $ratio      = $ratios[$provider];                  dump("Ratio: " . $ratio);

            $msgs_capacity  = $consumers * $ratio;                    dump("Capa: ". $msgs_capacity);
            $msgs_orphan    = $msgs_ready - floor($msgs_capacity);
            $msgs_orphan    = ($msgs_orphan <= 0) ? 0 : $msgs_orphan; dump("Huerfanos: " . $msgs_orphan);
            $needed_consumers  = ceil( $msgs_orphan / $ratio );       dump("Cons necesarios: " . $needed_consumers);

            if ($needed_consumers > 0) {
                $max_consumers = $this->getMaxConsumers( $provider );
                if( $consumers == $max_consumers ) {
                    echo "Se requieren " . $needed_consumers . " para <b>" .$provider."</b> pero ya se alcanzó el limite. (Curr: ".$consumers.". Máx: ". $max_consumers .") <br />";
                    continue; // Procese las otras colas
                }

                // Se requerían N pero el máximo es M (N>M). Se piden X tal que A+X = M, donde A es la cant actual
                if ($consumers + $needed_consumers > $max_consumers) {
                    // TO-DO indicar que no se siga iterando esta Queue así falten consumers
                    // quizá hackear a mano los objetos? hacer flags de ignorar?
                    echo "Se requerían " . $needed_consumers . " cons para <b>" .$provider."</b> pero se excedería el límite. (Curr: ".$consumers.". Máx: ". $max_consumers .") <br />";
                    echo "Needed consumers: <br />";
                    dump("Maxcon: ". $max_consumers);
                    dump("Con: " . $consumers);
                    $needed_consumers = $max_consumers - $consumers;
                }

                $this->consGoalInitial[$provider] = (int)$needed_consumers; //dump("Needed: " . $needed_consumers);
            }
        }
    }

    private function processConsumersQueueOD() {
        echo "Cola de consumidores necesarios: <br />"; dump($this->consumersQueue);
        
        $srvtype_best_indep = $this->getBestIndepServerTypes();
        // OJO: Si no se pueden servir los consumers pedidos, el array resultado trae
        // ["proveedor"] => [ "name"=>null, "instances"=>null ];

        echo "Lista de mejores independientes: <br />"; dump($srvtype_best_indep);

        arsort($this->consumersQueue); //De mayor a menor 
        while(! empty($this->consumersQueue) ) {
            //TO-DO
            break;
        }
    }

    private function getBestIndepServerTypes(){
        $result = [];
        foreach( $this->consumersQueue as $provider => $cons_requested ) {
            $result[$provider] = $this->getBestServerTypeConfig($provider, $cons_requested);
        }
        return $result;
    }

    private function getBestServerTypeConfig($provider, $cons_requested){

        $running_match = [
            "name"      => null,
            "instances" => null
        ];

        foreach ($this->serverTypes as $serverType) {

            $cons_per_instance = $serverType->getConsumers($provider);
            if( $cons_per_instance <= 0 ) continue;

            $instances_needed = (int)ceil($cons_requested / $cons_per_instance);
            if (
                // Se revisa si crea menos instancias que lo anteriormente guardado (si esta iteración es un mejor match)
                ( ( is_null($running_match["instances"]) ) ||
                ( $running_match["instances"] > $instances_needed ) ) 
            ){
                $running_match["instances"] = $instances_needed;
                $running_match["name"] = $serverType->getName();
            }
        }
        return $running_match;
    }

    /*

    private function balanceWithRatiosProgressive(){

        // Activar simulación. Comentar para usar nativo
        $this->useSimulatedQueues(); // Simular colas

        echo "<h5> Iteración ". $this->laps . " </h5><br />";
        
        $curr_consumers  = $this->getAvaliableConsumers();
        $curr_messages   = $this->getUnprocessedMessages(); // Real de Rabbit
        $curr_ratios     = $this->getWorkerMessageRatios();

        echo "Configuración general: <br />";
        dump($this->config);
        echo "Tipos de servidor e instancias: <br />";
        dump($this->serverTypes);
        echo "Colas de RabbitMQ: <br />";
        dump($this->queues);

        echo "Consumers: <br />";
        dump($curr_consumers);
        echo "Msgs pendientes: <br />";
        dump($curr_messages);
        echo "Ratios: <br />";
        dump($curr_ratios);
        echo "Proveedores excluidos: <br />";
        dump($this->providersExcluded);
        echo "<br /><br />";


        foreach( $curr_messages as $provider => $msgs_total ) { // Iterar por cada Queue
            
            echo "<hr> <h3>" . $provider . "</h3>";

            $consumers  = $curr_consumers[$provider]; //dump("Consumers: " . $consumers);
            $msgs_ready = $curr_messages[$provider];  //dump("Msjs: " . $msgs_ready);
            $ratio      = $curr_ratios[$provider];    //dump("Ratio: " . $ratio);

            $msgs_capacity  = $consumers * $ratio; //dump("Capa: ". $msgs_capacity);
            $msgs_orphan    = $msgs_ready - floor($msgs_capacity);
            $msgs_orphan    = ($msgs_orphan <= 0) ? 0 : $msgs_orphan; //dump("Huerfanos: " . $msgs_orphan);
            $needed_consumers  = ceil( $msgs_orphan / $ratio ); //dump("Req consum:" . $needed_consumers);

            if ($needed_consumers > 0) {

                $max_consumers = $this->getMaxConsumers( $provider );

                if( $consumers == $max_consumers ) {
                    echo "Se requieren " . $needed_consumers . " para <b>" .$provider."</b> pero ya se alcanzó el limite. (Curr: ".$consumers.". Máx: ". $max_consumers .") <br />";
                    continue; // Procese las otras colas
                }

                // Se requerían N pero el máximo es M (N>M). Se piden X tal que A+X = M, donde A es la cant actual
                if ($consumers + $needed_consumers > $max_consumers) {
                    // TO-DO indicar que no se siga iterando esta Queue así falten consumers
                    // quizá hackear a mano los objetos? hacer flags de ignorar?
                    echo "Se requerían " . $needed_consumers . " para <b>" .$provider."</b> pero se excedería el límite. (Curr: ".$consumers.". Máx: ". $max_consumers .") <br />";
                    //echo "Needed consumers: <br />";
                    //dump("Maxcon: ". $max_consumers);
                    //dump("Con: " . $consumers);
                    //$needed_consumers = $max_consumers - $consumers;
                //}

                //dump("Needed: " . $needed_consumers);
                $this->queueConsumerRequest( $provider, $needed_consumers );
            }
        }
        if( $this->laps == 25 ) die();
        if( $this->laps != 25 ) $this->laps++;

        // Al terminar de colocar en cola los nuevos consumers necesarios,
        
        // Opción 1 modifica los items existentes a fin de iterar.
        $this->processConsumersQueueRecursive(); // Opción 1.

        // Opción 2 es más complicado y en ultimas puede que sea igual
        //$this->processConsumersQueueHeuristic(); // Opción 2.
    }   
    */

    private function queueConsumerRequest( $provider, $needed_consumers )
    {
        echo "Se intentará pedir ". $needed_consumers . " cons a " . $provider ." <br />";
        // TO-DO actualizar objetos de estado
        $this->consumersQueue[ $provider ] = (int)$needed_consumers;
    }

    private function processConsumersQueueRecursive()
    {
        // Ojo, este método cambia las instancias almacenadas al iterar
        // TO-DO separar ServerType de iteracion del leido en vivo.

        echo "<br /> <b>processConsumersQueueRecursive </b><br />";
        echo "ConsumersQueue: <br />";
        dump($this->consumersQueue);

        echo "ConsumersQueue al retirar los excluidos: <br />";
        $this->removeExcludedProvidersFromQueue();
        dump($this->consumersQueue);

        // Busca qué proveedor tiene más mensajes en cola
        $largest = $this->getLargestArrAssoc( $this->consumersQueue );
        $this->requestConsumers( key($largest), reset($largest) );

        while(! empty($this->consumersQueue) ) {

            $this->balanceWithRatiosProgressive(); // Itera de forma recursiva

            // Detectar final
            if( (empty($this->consumersQueue)) && ($this->flagReport == false) ){
                echo "<h1 style='margin-bottom:0;padding-bottom:0;'> Plan de acción: </h1><br />";

                $report = self::compactReport( $this->instancesSuggested );
                dump( $report );
                $this->flagReport = true;
            }
        }
    }

    //
    private static function compactReport( $arr_server_types )
    {
        // Crear vector con estructura ["provider":"valor"]
        $results = [];
        foreach( $arr_server_types as $srvtype_conf ) {
            $prev_value = 0;
            if( key_exists($srvtype_conf["name"], $results) ){
                $prev_value = $results[ $srvtype_conf["name"] ];
            }
            $results[ $srvtype_conf["name"] ] = $prev_value + (int)$srvtype_conf["instances"];
        }

        // Formatear para estructura "JSON"
        $results_fmt = [];
        foreach($results as $name => $instances){
            $results_fmt[] = [
                "name"      => $name,
                "instances" => $instances
            ];
        }
        return $results_fmt;
    }

    private function removeExcludedProvidersFromQueue()
    {
        foreach($this->consumersQueue as $provider => $consumers_requested){
            if ( in_array($provider, $this->providersExcluded) ){
                unset( $this->consumersQueue[$provider] );
            }
        }
    }

    private function requestConsumers( $provider, $new_consumers_nr )
    {  
        echo "Eligiendo tipo de servidor para provider con mayor numero de consumers <br />";
        dump("Provedor elegido: ". $provider);
        dump("Solicitados: " . $new_consumers_nr);

        $type = $this->chooseServerType( $provider, $new_consumers_nr );
        // Si $type es false, no se halló un servidor que cumpla

        if( $type ){

            echo("El ServerType óptimo es: <br />");
            dump($type);
            // Inyectar las instancias nuevas (se modifica el objeto inicial para "llevar cuenta")
            $this->getServerByName( $type["name"] )->addInstances( $type["instances"] );
            $this->instancesSuggested[] = $type; // Añade para resumen al final

        } else {
            echo "No hay un ServerType óptimo, por lo que ignoramos al proveedor " . $provider . ".<br />";
        }

        dump( $this->consumersQueue );
        unset( $this->consumersQueue[$provider] );
        echo "Se vació la lista del proveedor " . $provider . "<br />";
        dump( $this->consumersQueue );
        
    }

    private function chooseServerType ($provider, $consumers_requested)
    {
        $config_initial = [
            "name"      => null,
            "instances" => null,
        ];
        $config_suggested = $config_initial;

        while( is_null($config_suggested["instances"]) ){

            $config_suggested = $this->getMatchingServerType($provider, $consumers_requested, $config_initial);

            // Reducir un consumer para la próxima iteración
            if( is_null($config_suggested["instances"])){
                echo "No se encontró un servidor apropiado para este nº de consumers (" .$consumers_requested. "). Consumers-- <br />";
                $consumers_requested--;
            }

            // Detectar cuando parar el bucle
            if ( $consumers_requested == 0 ) {
                $this->providersExcluded[] = $provider;
                echo "<b>Se ha excluido el proveedor " .$provider. " de las próximas iteraciones.</b><br />";
                break;
            }
        }
       
        // Si no está null, se encontró un match :)
        if(! is_null($config_suggested["instances"]) ){
            echo "<br /><b>Se encontró una configuración recomendada:</b> "; dump($config_suggested);
            return $config_suggested;
        } else {
            echo "Ningun tipo de servidor cumple con el escalamiento requerido :(.<br />";
            return false;
        }
    }

    private function getMatchingServerType($provider, $consumers_requested, $running_match)
    {
        foreach ($this->serverTypes as $serverType) {

            $instance_consumers_nr = $serverType->getConsumers($provider);
            if( $instance_consumers_nr <= 0 ) continue;

            $instances_needed = (int)ceil($consumers_requested / $instance_consumers_nr);
            if (
                // Primero se revisa si crea menos instancias que lo anteriormente guardado
                // (Es decir, si esta iteración es un mejor match)
                   ( ( is_null($running_match["instances"]) ) || ( $running_match["instances"] > $instances_needed ) ) 
                // Además se debe revisar que con las nuevas instancias no se sobrepasen los máximos de otros proveedores
                && ( $this->willConfigRespectMaxLimits( $serverType->getConsumers(), $instances_needed ) )
            ) {
                $running_match["instances"] = $instances_needed;
                $running_match["name"] = $serverType->getName();
            }
        }
        return $running_match;
    }

    private function willConfigRespectMaxLimits( $consumers_server_type, $instances ){
        
        $consumers_max  = $this->getMaxConsumers();
        $consumers_curr = $this->getAvaliableConsumers();
        
        echo "WCBBM - Max consumers: <br />";
        dump($consumers_max); 
        echo "WCBBM - Consumers in: ";
        dump($consumers_server_type);
        echo "WCBBM - Requested instances: ";
        dump($instances);

        foreach( $consumers_server_type as $provider => $consumers_prov ){ 
            
            $consumers_new = $consumers_prov * $instances;
            $consumers_prediction = $consumers_curr[ $provider ] + $consumers_new;

            if( $consumers_prediction > $consumers_max[$provider] ){
                echo "Al crear este número de instancias se excede el numero máximo de consumers permitidos para ". $provider . ".";
                echo "(Curr: " . $consumers_curr[$provider] . ". Max: " . $consumers_max[$provider] . ". Pred:" . $consumers_prediction . ").<br />";
                echo "Este metodo se invoca en un loop, por lo que se seguirá buscando un servidor adecuado. <br />";
                return false;
            }

        }
        return true;
    }
}