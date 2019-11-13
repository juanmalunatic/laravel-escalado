






















    // ---------------------------------------------------------------------

    private function balanceWithEstimatedTime() {

        $consumers     = $this->getAvaliableConsumersOld();  // Contar los consumidores disponibles
        $messages      = $this->getUnprocessedMessages(); // Contar el numero de mensajes esperando
        $request_times = $this->getRequestTimes();        // Tiempos de respuesta

        if( $this->flag ) echo "<h2> Segunda iteracion </h2>";

        /*echo "Consumers: <br />";
        dump($consumers);
        echo "Msgs pendientes: <br />";
        dump($messages);
        echo "Req times: <br />";
        dump($request_times);
        echo "<br /><br />";*/

        foreach( $messages as $provider => $messages_total ) { // Iterar por cada Queue

            $estimated_speed    = $consumers[$provider] / $request_times[$provider];
            $estimated_messages = $estimated_speed * $this->config["frequency"];

            // Verbose
            echo "<hr />";
            echo "<h3>" . $provider . "</h3>";
            echo "Hay " . $messages[$provider] . " mensajes pendientes.";
            echo "<br />";
            echo "Hay " . $consumers[$provider] . " trabajadores.";
            echo "<br />";
            echo "Cada trabajador demora aprox. " . $request_times[$provider] ."ms en procesar un mensaje.";
            echo "<br />";
            dump($estimated_speed * 1000 . " msg/seg.");
            dump($estimated_messages . " msgs en " . $this->config["frequency"] . " ms.");

            $messages_left = $messages_total - floor($estimated_messages);
            dump($messages_left);
            if( $messages_left > 0 ){
                // Se requieren N trabajadores adicionales 
                $additional_speed     = $messages_left / $this->config["frequency"];
                $additional_consumers = (int) ceil( $additional_speed * $request_times[$provider] );
                echo "Se requieren " . $additional_consumers . " adicionales. <br />";
                $this->consumersRequest[$provider] = $this->getSafeAdditionalConsumers( $provider, $additional_consumers );
            }
        }

        echo "Consumers request: <br />";
        dump($this->consumersRequest);

        // Itera por todos los providers que solicitaron consumers nuevos
        /*$this->consumersRequest["bolivar"] = 20; // <- pal debug
        $this->requestConsumersOld(); */

        if( $this->flag ) die();

        // TO-DO revisar hasta acá
        // Después de haber solicitado (puesto en cola) una instancia de servidor
        // se deben recalcular los consumers necesarios.
        // La recursión se rompe cuando se hayan procesado todos.

        while( $this->requestConsumersOld() ){
            $this->flag = true;
            $this->balanceWithEstimatedTime();
        }

        echo "TO-DO: Proceso la cola. <br />";
        echo "TO-DO: Reinicio el proceso. <br />";

    }

    // Revisa que los consumers a pedir no desborde el máximo posible
    private function getSafeAdditionalConsumers($provider, $additional_consumers) {

        $consumers      = $this->getAvaliableConsumersOld(); 
        $max_consumers  = $this->getMaxConsumers();
        $limit_overflow = ($consumers[$provider] + $additional_consumers) - $max_consumers[$provider];

        if( $limit_overflow <= 0 ) { // Menor a cero: No se desbordó el limite
            return $additional_consumers;
        } else {
            return $this->getSafeAdditionalConsumers( $provider, $additional_consumers - $limit_overflow);
        }
    }

    private function requestConsumersOld()
    {
        if( empty($this->consumersRequest) ){
            return false;
        }

        $reqs = $this->consumersRequest;
        // Prioriza de acuerdo a quien tenga más consumers
        arsort($reqs);
        reset($reqs);
        $key_first_item = key($reqs);
        
        $new_server_inst = $this->findOptimalServerType(
            $key_first_item,
            $reqs[ $key_first_item ]
        );

        //dump($new_server_inst);
        
        $this->requestServerInstance(
            $new_server_inst["name"],
            $new_server_inst["required_instances"]
        );
        unset( $this->consumersRequest[ $key_first_item ] );
        return true;
    }

    private function findOptimalServerType ($resource, $consumers_requested) {

        /*dump($resource);
        dump($consumers_requested);
        dump($this->serverTypes);*/

        // Calcula cuantos servidores se necesitan de cada tipo
        $servers_quotient = [];
        foreach( $this->serverTypes as $server ){ // Iteramos los tipos de servidor
            $instance_consumers = $server->getConsumers();
            if( $instance_consumers[$resource] != 0 ){
                $servers_quotient[$server->getName()] = (int)ceil($consumers_requested / $instance_consumers[$resource] );
            }
        }
        //TO-DO QUE PASA CON ARRAY VACIO
        asort($servers_quotient);
        reset($servers_quotient);

        $first_item = key($servers_quotient);
        $retarr["name"] = $first_item;
        $retarr["required_instances"] = $servers_quotient[$first_item];

        return $retarr;
    }

    public function requestServerInstance ($server_name, $required_instances) {
        // TO-DO acá se debe crear una cola de peticiones
        echo "Pediré a GC " . $required_instances . " instancias de " . $server_name . ".<br />";
        
        //dump( $this->serverTypes );
        $server = $this->getServerByName($server_name);
        $server->addInstances( $required_instances );
        //dump( $this->serverTypes );
    }
}