<?php

// Klassendefinition
class RobonectWifiModul extends IPSModule
{
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
        $this->RegisterPropertyString("IPAddress", '0.0.0.0');
        $this->RegisterPropertyString("Username", '');
        $this->RegisterPropertyString("Password", '');
    }

    public function ApplyChanges()
    {
        /* Called on 'apply changes' in the configuration UI and after creation of the instance */
        parent::ApplyChanges();

        // Generate Profiles & Variables
        $this->registerProfiles();
        $this->registerVariables();

        // Set Data to Variables (and update timer)
        $this->Update();
    }


    public function Update()
    {
        // get data via HTTP Request
        $IPAddress = trim($this->ReadPropertyString("IPAddress"));
        $Username = trim($this->ReadPropertyString("Username"));
        $Password = trim($this->ReadPropertyString("Password"));

        // HTTP status request
        $URL = 'http://' . $IPAddress . '/json?cmd=status';
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $Username . ':' . $Password,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $json = curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            return false;
        };

        if ($json == false) {
            return false;
        } else {
            // set values to variables
            $data = json_decode($json, true);
            $dataStatus = $data['status'];
            SetValue($this->GetIDForIdent("name"), $data['name']);
            SetValue($this->GetIDForIdent("status"), $data['status']['status']);
            SetValue($this->GetIDForIdent("distance"), $data['status']['distance']);
            SetValue($this->GetIDForIdent("stopped"), $data['status']['stopped']);
            $statusSinceTimestamp = time() - $data['status']['duration'];
            SetValue($this->GetIDForIdent("statusSince"), $statusSinceTimestamp);
            SetValue($this->GetIDForIdent("mode"), $data['status']['mode']);
            SetValue($this->GetIDForIdent("batterySOC"), $data['status']['battery']);
            SetValue($this->GetIDForIdent("hours"), $data['status']['hours']);
        }

    }

    protected function registerProfiles()
    {
        // Generate Variable Profiles
        if (!IPS_VariableProfileExists('ROBONECT_Status')) {
            IPS_CreateVariableProfile('ROBONECT_Status', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Status', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 0, "Status wird ermittelt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 1, "geparkt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 2, "mäht", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 3, "sucht die Ladestation", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 4, "lädt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 5, "sucht", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 7, "Fehlerstatus", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 8, "Schleifensignal verloren", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 16, "abgeschaltet", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 17, "schläft", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_Modus')) {
            IPS_CreateVariableProfile('ROBONECT_Modus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Modus', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 0, "automatisch", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 1, "manuell", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 2, "Zuhause", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 3, "Demo", "", 0xFFFFFF);
        }

        if ( !IPS_VariableProfileExists('ROBONECT_JaNein') ) {
            IPS_CreateVariableProfile('ROBONECT_JaNein', 0 );
            IPS_SetVariableProfileIcon('ROBONECT_JaNein', '' );
            IPS_SetVariableProfileAssociation("ROBONECT_JaNein", true, "Ja", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_JaNein", false, "Nein", "", 0xFFFFFF);
        }

        if ( !IPS_VariableProfileExists('ROBONECT_Stunden') ) {
            IPS_CreateVariableProfile('ROBONECT_Stunden', 1 );
            IPS_SetVariableProfileDigits('ROBONECT_Stunden', 0 );
            IPS_SetVariableProfileIcon('ROBONECT_Stunden', 'Clock' );
            IPS_SetVariableProfileText('ROBONECT_Stunden', "", " h" );
        }

    }

    protected function registerVariables()
    {

        //--- Basic Data ---------------------------------------------------------
        $this->RegisterVariableString("name", "Name", "", 0);

        //--- Status -------------------------------------------------------------
        $this->RegisterVariableInteger("status", "Status", "ROBONECT_Status", 10);
        $this->RegisterVariableInteger("distance", "Entfernung", "", 11);
        $this->RegisterVariableBoolean("stopped", "man. angehalten", "ROBONECT_JaNein", 12);
        $this->RegisterVariableInteger("statusSince", "Status seit", "~UnixTimestamp", 13);
        $this->RegisterVariableInteger("mode", "Modus", "ROBONECT_Modus", 14);
        $this->RegisterVariableInteger("batterySOC", "Akkustand", "~Intensity.100", 15);
        $this->RegisterVariableInteger("hours", "Arbeitsstunden", "ROBONECT_Stunden", 16);

        //--- Timer --------------------------------------------------------------

        //--- WLAN ---------------------------------------------------------------

        //--- Health -------------------------------------------------------------


    }

}