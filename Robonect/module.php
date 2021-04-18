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

    public function Create()
    {
        /* Create is called ONCE on Instance creation and start of IP-Symcon.
           Status-Variables und Modul-Properties for permanent usage should be created here  */
        parent::Create();

        // Properties Robonect Wifi Module
        $this->RegisterPropertyString("IPAddress", '0.0.0.0' );
        $this->RegisterPropertyString("Username", '' );
        $this->RegisterPropertyString("Password", '' );
    }



        public function Update() {
            $IPAddress = trim($this->ReadPropertyString("IPAddress"));
            $Username  = trim($this->ReadPropertyString("Username"));
            $Password  = trim($this->ReadPropertyString("Password"));

            // get data via HTTP status request
            $ch = curl_init('http://'.$IPAddress.'/json?cmd=status&user='.$Username.'&pass='.$Password );
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $json = curl_exec($ch);
            curl_close ($ch);

            if ( $json == false) return false;
            return json_decode($json,true);
        }
}