<?php
return ['name' => 'Synthetic worker', 'env' => 'testing', 'key' => 'base64:'.base64_encode(str_repeat('w', 32)), 'cipher' => 'AES-256-CBC'];
