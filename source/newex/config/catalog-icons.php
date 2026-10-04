<?php
// Official exchange/issuer artwork. Identity evidence and original URL accompany every file.
return json_decode(file_get_contents(__DIR__.'/catalog-icons.json'),true,64,JSON_THROW_ON_ERROR);
