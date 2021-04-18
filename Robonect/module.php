<?php
// Klassendefinition
class RobonectWifiModul extends IPSModule {
    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
     *
     * ABC_MeineErsteEigeneFunktion($id);
     *
     */
    public function Update() {
        $json  = file_get_contents('http://192.168.1.30/json?cmd=status');
        if ($json == false) return false;
        return json_decode($json,true);
    }
}