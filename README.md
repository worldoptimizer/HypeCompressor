# HypeCompressor

![](https://playground.maxziebell.de/Hype/Compressor/HypeCompressor.png?)

Project aimed at different ways to reduce the file footprint and apply optimizations to Tumult Hype 4 export files.


Run code that was zipped and base 64 encoded.
This example is `alert('Hello World');`:
```javascript
HypeCompressor.run("eNpLzEktKlE1slA1MipPLFY1MshILUoFclSNLFWNnQCNKQha");
```

To generate base 64 encoded zipped content use the following:

*Compression can vary from 1-9. Using 6 (as SWF did in the old days) in these snippets.*

**PHP example** with `$code` being your code.
```php
base64_encode(gzcompress(rawurlencode($code),6))
```

**Python example** with `code` being your code.
```python
import urllib
import base64
import zlib

base64.b64encode(zlib.compress(urllib.quote(code), 6))
```

---


You can also load manually created zip files and run all JS included in it.
Hype generated script files are always sorted to the end.
```javascript
HypeCompressor.load("${resourcesFolderName}/myJavaScriptLibraries.zip",function(files){
	HypeCompressor.run(files);
});	
```

You can also load an entire Hype project and the connected resources from a zip-file.
```javascript
var resources;

HypeCompressor.load("resources.zip",function(files){
	resources = files;
	HypeCompressor.run(files);
});	

function HypeResourceLoad(hypeDocument, element, event) {
	if(!resources) return;
	var file = event.url.split('/').pop();
	var type = file.toLowerCase().split('.').pop();
	for(var i=0; i<resources.length; i++){
		if (resources[i][1].substr(0, 1)=='.') continue;
		if (resources[i][1] == file){
			switch (type){
				case 'jpg': case 'png': case 'gif': case 'svg':
					return 'data:image/'+type+';base64,'+window.btoa(resources[i][0]);
					break;
			}
		}
	}
}


if("HYPE_eventListeners" in window === false) { window.HYPE_eventListeners = Array(); }
window.HYPE_eventListeners.push({"type":"HypeResourceLoad", "callback":HypeResourceLoad});
```

Loading using the `HypeCompressor.load` method relies on `XMLHttpRequest`. Meaning to use it you can't run from `file://` as that violates Cross-Origin Resource Sharing (CORS) policies.

