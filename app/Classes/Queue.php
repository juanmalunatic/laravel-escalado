<?php

namespace App\Classes;

class Queue {

	const PREFIJO = "agm-services-";

	private $name;
	private $consumers;
	private $messages;
	
	public function __construct($api_data){
		$this->processApi($api_data);
	}

	private function processApi($api_data){

		$this->consumers = $api_data->consumers;
		$this->messages["ready"]   = $api_data->messages_ready;
		$this->messages["unacked"] = $api_data->messages_unacknowledged;
		$this->messages["total"]   = $api_data->messages;

		$this->name = str_replace(self::PREFIJO, "", $api_data->name);

		//dump($this);
	}

	public function getName(){
		return $this->name;
	}

	public function getConsumers(){
		return $this->consumers;
	}

	public function setConsumers($value){
		return $this->consumers = $value;
	}

	public function getMessages( $subtype = null ){
		if( is_null($subtype) ){
			return $this->messages;
		} else {
			return $this->messages[$subtype];
		}
	}

	public function setMessages( $subtype = null, $value ){
		if( is_null($subtype) ){
			$this->messages = $value;
		} else {
			$this->messages[$subtype] = $value;
		}
	}
}