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
            $IPAddress = trim($this->ReadPropertyString("IPAddressCharger"));
            $Username  = trim($this->ReadPropertyString("Username"));
            $Password  = trim($this->ReadPropertyString("Password"));

            $data  = file_get_contents('http://'.$IPAddress.'/json?cmd=status&user='.$Username.'&pass='.$Password );
            if ( $data == false) return false;
            return json_decode($data,true);
        }
}