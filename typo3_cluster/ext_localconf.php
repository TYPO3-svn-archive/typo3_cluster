<?php
$TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['t3lib/class.t3lib_db.php'] = t3lib_extMgm::extPath('typo3_cluster').'class.ux_t3lib_db.php';
$TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['t3lib/class.t3lib_extfilefunc.php'] = t3lib_extMgm::extPath('typo3_cluster').'class.ux_t3lib_extfilefunc.php';
$TYPO3_CONF_VARS['FE']['eID_include']['cluster_worker'] = 'EXT:typo3_cluster/worker.php';
$TYPO3_CONF_VARS['SC_OPTIONS']['typo3_cluster/class.ux_t3lib_db.php']['ux_t3lib_db-PostProc'][]='EXT:typo3_cluster/class.loadbalance.php:user_typo3_cluster_loadbalance->setEnv';
$TYPO3_CONF_VARS['SC_OPTIONS']['typo3_cluster/class.ux_t3lib_db.php']['ux_t3lib_db-PostProc'][]='EXT:typo3_cluster/class.loadbalance.php:user_typo3_cluster_loadbalance->main';
?>