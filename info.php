<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache reseteado.";
} else {
    echo "OPcache no está habilitado o no puedo resetearlo.";
}
