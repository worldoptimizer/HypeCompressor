<?php
require_once ("HypeDocumentLoader.php");

/**
* Hype Compressor for PHP v1.0.4
* Compress generated script by Tumult Hype 4
*
* @author	 Max Ziebell <mail@maxziebell.de>
*
*/

/*
Version history:
1.0.0 Initial release under existing CJSON license
1.0.1 Added delete_scene_by_index and delete_scene_by_name
1.0.2 Refactored and added closure compression
1.0.3 Set function lookup to identifier, improved string lookup
1.0.4 Removed rawurlencode from compress(), unicode handling done by JS TextDecoder since HypeCompressor.js 1.0.7
*/


class HypeCompressor extends HypeDocumentLoader{
	public $loader_object;
	public $string_lookup = [];
	public $string_lookup_count = [];
	public $scene_objects_lookup = [];
	public $scene_objects_encoded_lookup = [];
	public $hype_functions;
	public $hype_functions_minified;
	public $hype_functions_lookup=[];
	public $compiled_js;


	function __construct($hype_generated_script=null) {
		parent::__construct($hype_generated_script);
		if($hype_generated_script && empty($this->loader_object)) $this->loader_object = $this->get_loader_object();
	}

	public function compress($string) {
		return base64_encode(gzcompress($string, 9));
	}

	/**
	 * helper to compress strings with lookup
	*/

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

		// create lookup counting string occurances
		$iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($this->loader_object));
		foreach($iterator as $key => $value) {
			if (is_string($value) && !preg_match('/^[0-9"]+$/',$value)) {
				if(strlen($value)>3) $this->string_lookup_count[$value] +=1;	
			}
			
			if (is_string($key) && !preg_match('/^[0-9"]+$/',$key)) {
				if(strlen($key)>5) $this->string_lookup_count[$key] +=1;
			}
		}

		// create string lookup itself based on previous count data
		foreach($iterator as $key => $value) {
			if (is_string($value) && !preg_match('/^[0-9"]+$/',$value)) {
				// value string Lookup
				if(strlen($value)>3 && $this->string_lookup_count[$value] && $this->string_lookup_count[$value]>1){
					$fid = array_search($value, $this->string_lookup);
					if($fid===false) $this->string_lookup[] = $value;
				}
			}

			// (optional) key string Lookup
			if (is_string($key) && !preg_match('/^[0-9"]+$/',$key)) {
				if(strlen($key)>5 && $this->string_lookup_count[$key] && $this->string_lookup_count[$key]>1){
					$fid = array_search($key, $this->string_lookup);
					if($fid===false) $this->string_lookup[] = $key;
				}
			}
		}

		// to give smaller ids to strings often used
		usort($this->string_lookup, array($this, "sort_based_on_count"));

		// apply string lookup to loader object data
		foreach($iterator as $key => $value) {
			if (is_string($value) && !preg_match('/^[0-9"]+$/',$value)) {
				// value string Lookup assign
				if(strlen($value)>3 && $this->string_lookup_count[$value] && $this->string_lookup_count[$value]>1){
					$fid = array_search($value, $this->string_lookup);
					$iterator->getInnerIterator()->offsetSet($key, '_['.$fid.']');
				}
			}

			// (optional) key string Lookup assign
			if (is_string($key) && !preg_match('/^[0-9"]+$/',$key)) {
				if(strlen($key)>5 && $this->string_lookup_count[$key] && $this->string_lookup_count[$key]>1){
					$fid = array_search($key, $this->string_lookup);
					$iterator->getInnerIterator()->offsetUnset($key);
					$iterator->getInnerIterator()->offsetSet('[_['.$fid.']]', $value);
				}
			}	
		}

		// add lookup to generated script
		$this->insert_into_document_loader('var _='.$this->encode($this->string_lookup).';');
	}


	/**
	 * helper to compress scenes objects with lookup (great for symbols)
	 */

	public function compress_scene_objects(){
		if(empty($this->loader_object)) $this->loader_object = $this->get_loader_object();
		// dump to loader object to screen and exit this function (debugging)
		// echo '<pre>'; print_r($this->loader_object); echo '</pre>'; exit;
		
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

	/**
	 * helper to delete scenes 
	 */

	public function delete_scene_by_index($idx){
		if(empty($this->loader_object)) $this->loader_object = $this->get_loader_object();

		// determin layouts to delete
		$layoutsToDelete = $this->loader_object->sceneContainers[$idx]->X;

		// unset layouts
		foreach ($layoutsToDelete as $j) unset($this->loader_object->scenes[$j]);

		// reindex layouts
		$this->loader_object->scenes = array_values($this->loader_object->scenes);

		// reduce index of layouts higher by count of deleted
		for ($i = 0; $i < count($this->loader_object->scenes); $i++) $this->loader_object->scenes[$i]->_ = $i;

		// unset and reindex sceneContainer
		unset($this->loader_object->sceneContainers[$idx]);
		$this->loader_object->sceneContainers = array_values($this->loader_object->sceneContainers);

		// walk over them and fix layout indexes
		for ($i = $idx; $i < count($this->loader_object->sceneContainers); $i++) {
			for ($j = 0; $j < count($this->loader_object->sceneContainers[$i]->X); $j++) {
				if($this->loader_object->sceneContainers[$i]->X[$j]>=$layoutsToDelete[0]) 
					$this->loader_object->sceneContainers[$i]->X[$j] -= count($layoutsToDelete);
			}
		}
	}

	public function delete_scene_by_name($name){
		if(empty($this->loader_object)) $this->loader_object = $this->get_loader_object();

		// loop over sceneContainer looking for name match
		for ($i = 0; $i < count($this->loader_object->sceneContainers); $i++) {
			if ($this->loader_object->sceneContainers[$i]->n == $name){
				$this->delete_scene_by_index($i);
			}
		}
			
	}

	/**
	 * functions to extract JS functions into window scope
	 */

	public function compress_functions_with_closure(){
		$this->extract_functions_to_window_scope(true);
	}

	public function populate_hype_functions ($match) {
		$this->hype_functions_lookup[stripcslashes($match[3])] = stripcslashes($match[2]);
		$new_name = '_'.$match[3];
		return 'name:"'.$match[1].'",source:"'.$new_name.'",identifier:"'.$match[3].'"';
	}

	public function extract_functions_to_window_scope($minify=false){
		$loader_begin_string = $this->hype_generated_script_parts->loader_begin_string;
		$this->hype_functions_lookup = [];
		$this->hype_functions ='';

		$loader_begin_string = preg_replace_callback(
			'|name:"(.*?)",source:"(.*?)",identifier:"([0-9]+)"|',
			array($this, "populate_hype_functions"),
			$loader_begin_string
		);
		
		foreach($this->hype_functions_lookup as $name => $function){
			$this->hype_functions .= 'var _'.$name.'='.$function.';';
		}
		
		if (count($this->hype_functions_lookup)){
			if($minify){
				$data = $this->minify_with_closure_compiler($this->hype_functions);
				if ($data && count($data->errors)){
					foreach ($data->errors as $error) {
						$this->insert_into_generated_script("/* \n".$error->error."\n".$error->line."\n*/\n");
					}
				}
				if ($data && !empty($data->compiledCode)) {
					$this->hype_functions_minified = $data->compiledCode;
					$this->insert_into_generated_script($this->hype_functions_minified);
				} else {
					$this->insert_into_generated_script($this->hype_functions);	
				}
			} else {
				$this->insert_into_generated_script($this->hype_functions);
			}

			$this->hype_generated_script_parts->loader_begin_string = $loader_begin_string;
			return true;
		} else {
			return false;
		}
	}


	/**
	 * helper to format string for RFC2045
	 */

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

	/**
	 * functions for true ZIP compression using 
	 * https://cdn.jsdelivr.net/gh/worldoptimizer/HypeCompressor/HypeCompressor.min.js
	 */

	public function compress_and_wrap_with_run($js){
		$js_compressed = $this->compress($js);
		return $this->RFC2045($js_compressed, 'HypeCompressor.run("', ');');
	}

	public function hype_compressor_JS(){
		return file_get_contents('https://cdn.jsdelivr.net/gh/worldoptimizer/HypeCompressor/HypeCompressor.min.js')."\n";
	}

	/**
	 * Helper to communicate with closure API
	 */

	public function minify_with_closure_compiler($js) {
		if ($this->compiled_js) return $this->compiled_js;
		if (empty($js)) return;

		$args = 'js_code=' . urlencode($js);
		$args .= '&compilation_level=SIMPLE_OPTIMIZATIONS';
		$args .= '&output_format=json';
		$args .= '&output_info=compiled_code';
		$args .= '&output_info=errors';

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
		$this->compiled_js = json_decode($jscomp);
		return $this->compiled_js;
	}
	
}
