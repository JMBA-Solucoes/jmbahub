<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Session::checkCentralAccess();

if (!isset($_POST['users_id']) || !isset($_POST['is_active'])) {
    Html::back();
}

$usersId  = (int) $_POST['users_id'];
$isActive = (int) $_POST['is_active'];

$appuser = new PluginJmbahubAppuser();
$exists  = $appuser->getFromDBByCrit(['users_id' => $usersId]);

if ($exists) {
    $appuser->update([
        'id'        => $appuser->getID(),
        'is_active' => $isActive,
        'date_mod'  => date('Y-m-d H:i:s'),
    ]);
} else {
    $appuser->add([
        'users_id'      => $usersId,
        'is_active'     => $isActive,
        'date_creation' => date('Y-m-d H:i:s'),
        'date_mod'      => date('Y-m-d H:i:s'),
    ]);
}

Html::back();
