<?php

// This helper is based on and extends 
// https://github.com/worldoptimizer/HypeDocumentLoader-for-PHP
<?php
require_once ("HypeDocumentLoader.php");
 
class HypeCompressor extends HypeDocumentLoader{
	public $loader_object;
	public $string_lookup = [];
	public $string_lookup_count = [];
	public $scene_objects_lookup = [];
	public $scene_objects_encoded_lookup = [];
	public $hype_functions;
	public $hype_functions_minified;
	public $hype_functions_lookup=[];

	public function compress($string) {
		return base64_encode(gzcompress(rawurlencode($string),9));
	}

	private function sort_based_on_count($a, $b) {
		$aa = $this->string_lookup_count[$a];
		$bb = $this->string_lookup_count[$b];
		if ($aa == $bb) {
			return (strlen($a) < strlen($b)) ? -1 : 1;
		}
		return ($aa > $bb) ? -1 : 1;
	}

	public function compress_strings_in_document_data(){
		if(empty($this->loader_object)) $this->loader_object = $this->get_loader_object();

		$iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($this->loader_object));
		foreach($iterator as $key => $value) {
			if (!is_string($value) || preg_match('/^[0-9"]+$/',$value)) continue;
			if(strlen($value)>3) $this->string_lookup_count[$value] +=1;
			if(strlen($key)>5) $this->string_lookup_count[$key] +=1;
		}

		foreach($iterator as $key => $value) {
			if (!is_string($value) || preg_match('/^[0-9"]+$/',$value)) continue;
			
			// value string Lookup
			if(strlen($value)>3 && $this->string_lookup_count[$value] && $this->string_lookup_count[$value]>1){
				$fid = array_search($value, $this->string_lookup);
				if($fid===false) $this->string_lookup[] = $value;
			}

			// (optional) key string Lookup
			if(strlen($key)>5 && $this->string_lookup_count[$key] && $this->string_lookup_count[$key]>1){
				$fid = array_search($key, $this->string_lookup);
				if($fid===false) $this->string_lookup[] = $key;
			}
		}

		usort($this->string_lookup, array($this, "sort_based_on_count"));

		foreach($iterator as $key => $value) {
			
			if (!is_string($value) || preg_match('/^[0-9"]+$/',$value)) continue;

			// value string Lookup assign
			if(strlen($value)>3 && $this->string_lookup_count[$value] && $this->string_lookup_count[$value]>1){
				$fid = array_search($value, $this->string_lookup);
				$iterator->getInnerIterator()->offsetSet($key, '_['.$fid.']');
			}

			// (optional) key string Lookup assign
			if(strlen($key)>5 && $this->string_lookup_count[$key] && $this->string_lookup_count[$key]>1){
				$fid = array_search($key, $this->string_lookup);
				$iterator->getInnerIterator()->offsetUnset($key);
				$iterator->getInnerIterator()->offsetSet('[_['.$fid.']]', $value);
			}
		}

		$this->insert_into_document_loader('var _='.$this->encode($this->string_lookup).';');
	}

	public function compress_scene_objects(){
		if(empty($this->loader_object)) $this->loader_object = $this->get_loader_object();

		// loop over scenes
		for ($i = 0; $i < count($this->loader_object->scenes); $i++) {
			// loop over objects (ids)
			for ($j = 0; $j < count($this->loader_object->scenes[$i]->O); $j++) {
				// lookup id
				$id = $this->loader_object->scenes[$i]->O[$j];

				//make we have something
				if (empty($this->loader_object->scenes[$i]->v->{$id})) continue;

				// create object we might assign and fetch props
				$temp_object = (object)[];
				$bF = $this->loader_object->scenes[$i]->v->{$id}->bF;
				if ($bF) $temp_object->bF = $bF;
				$cV = $this->loader_object->scenes[$i]->v->{$id}->cV;
				if ($cV) $temp_object->cV = $cV;

				// do we have values to assign after collection?
				if (count((array)$temp_object)){
					// if it's only bF make it a number rather than a object
					if (count((array)$temp_object)==1 && isset($temp_object->bF)){
						$temp_object = $temp_object->bF;
					} else {
						$temp_object = $this->encode($temp_object);
					}	
					$temp_object = ','.$temp_object;	
				} else {
					$temp_object = '';
				}

				//unset props from branch
				unset($this->loader_object->scenes[$i]->v->{$id}->bF);
				unset($this->loader_object->scenes[$i]->v->{$id}->cV);

				//sort because it's pretty  ;-) and to make similiar
				$sort = new ArrayObject($this->loader_object->scenes[$i]->v->{$id});
				$sort->ksort();

				// encode for lookup signature
				$encoded = $this->encode($this->loader_object->scenes[$i]->v->{$id});

				//check if in lookup based on signature
				$fid = array_search($encoded, $this->scene_objects_encoded_lookup);
				if($fid===false) {
					//new and create
					$this->scene_objects_lookup[] = $this->loader_object->scenes[$i]->v->{$id};
					$this->scene_objects_encoded_lookup[] = $encoded;
					$fid = count($this->scene_objects_lookup)-1;
				}

				//reference	
				$this->loader_object->scenes[$i]->v->{$id} = '$('.$fid.$temp_object.')';
			}
		}

		$this->insert_into_document_loader('var sym='.$this->encode($this->scene_objects_lookup).';');
		$this->insert_into_document_loader('function $(c,a){var b=JSON.parse(JSON.stringify(sym[c]));if(a&&!(a instanceof Object))a={bF:a};Object.assign(b,a);return b}');

	}

	public function populate_hype_functions ($match) {
		print_r($match);
		$this->hype_functions_lookup[stripcslashes($match[1])] = stripcslashes($match[2]);
		$new_name = 'HYPE_functions[\\"'.$this->document_name.'\\"].'.$match[1];
		return 'name:"'.$match[1].'",source:"'.$new_name.'",identifier:"'.$match[3].'"';
	}

	public function extract_functions_to_window_scope($minify=false){
		$loader_begin_string = $this->hype_generated_script_parts->loader_begin_string;
		$this->hype_functions ='';
		$this->hype_functions .= "\n".'if ("HYPE_functions" in window === false) window.HYPE_functions = {};';
		$this->hype_functions .= "\n".'HYPE_functions["'.$this->document_name.'"] = {';
		
		$loader_begin_string = preg_replace_callback(
			'|name:"(.*?)",source:"(.*?)",identifier:"([0-9]+)"|',
			array($this, "populate_hype_functions"),
			$loader_begin_string
		);
		
		foreach($this->hype_functions_lookup as $name => $function){
			$this->hype_functions .= $name.':'.$function.',';
		}
		
		$this->hype_functions .= "\n".'};';

		if($minify){
			$this->hype_functions_minified = $this->minify_with_closure_compiler($this->hype_functions);
			if ($this->hype_functions_minified){
				$this->insert_into_generated_script($this->hype_functions_minified);
			}
		} else {
			$this->insert_into_generated_script($this->hype_functions);
		}

		$this->hype_generated_script_parts->loader_begin_string = $loader_begin_string;
	}

	public function RFC2045($string, $append='', $prepend=''){
		$first_chunk = 500-(strlen($append)+2);
		$string_RFC2045 = $append.substr($string, 0, $first_chunk).'"';
		$string_leftover = substr($string, $first_chunk);

		if ($string_leftover){
			$string_RFC2045 .= "+\n";
			$lines = str_split($string_leftover, 497);
			foreach ($lines as &$line) {
				$line= '"'.$line.'"';
			}
			$string_RFC2045 .= implode("+\n", $lines);
		}

		$string_RFC2045 .= $prepend;
		return $string_RFC2045;
	}


	public function compress_and_wrap_with_run($js){
		$js_compressed = $this->compress($js);
		return $this->RFC2045($js_compressed, 'HypeCompressor.run("', ');');
	}

	public function hype_compressor_JS(){
		return file_get_contents('https://cdn.jsdelivr.net/gh/worldoptimizer/HypeCompressor/HypeCompressor.min.js')."\n";
	}

	public function minify_with_closure_compiler($js) {
		if (empty($js)) return;

		// preapre arguments
		$apiArgs = array(
			'compilation_level' => 'SIMPLE_OPTIMIZATIONS',
			'output_format' => 'text',
			'output_info' => 'compiled_code'
		);

		$args = 'js_code=' . urlencode($js);
		foreach ($apiArgs as $key => $value) {
			$args .= '&' . $key . '=' . urlencode($value);
		}

		//API call using cURL
		$call = curl_init();
		curl_setopt_array($call, array(
			CURLOPT_URL => 'https://closure-compiler.appspot.com/compile',
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $args,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_HEADER => 0,
			CURLOPT_FOLLOWLOCATION => 0
		));
		$jscomp = curl_exec($call);
		curl_close($call);

		// return minified
		return ($jscomp);
	}
	
}
