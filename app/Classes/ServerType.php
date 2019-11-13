<?php

namespace App\Classes;

Class ServerType
{
	private $name;
	private $consumers;
	private $instancesInitial; 
	private $instances;
	private $providerCount = 0;

	public function __construct( $server_info ){
		$this->consumers = [];
		$this->loadConfiguration( $server_info );

		//TO-DO: leer instancias desde google cloud
		$this->simulateInstances();
	}

	private function loadConfiguration($server_info){

		$this->name = $server_info["name"];

		foreach( $server_info["consumers"] as $key => $value ){
			$this->consumers[$key] = $value;

			if( $value > 0 ){ // Cuenta los "sabores" que tiene el servidor
				$this->providerCount++;
			}
		}
	}

	private function simulateInstances(){

		$instances = 0;
		switch ($this->name) {
			case 'type-1':
				$instances = 0;
				break;

			case 'type-2':
				$instances = 0;
				break;

			case "type-3":
				$instances = 0;
				break;

			default:
				# code...
				break;
		}

		$this->instancesInitial = $instances;
		$this->instances = $instances;
	}

	public function getConsumers( $provider = null ){
		if( is_null($provider) ){
			return $this->consumers;
		}else{
			return $this->consumers[$provider];
		}
	}
	public function getInstances(){
		return $this->instances;
	}
	public function getName(){
		return $this->name;
	}
	public function addInstances( $num_to_add ){
		// TO-DO exception si $num_to_add < 0
		$this->instances += $num_to_add;
	}

	public function getProviderCount(){
		return $this->providerCount;
	}
}