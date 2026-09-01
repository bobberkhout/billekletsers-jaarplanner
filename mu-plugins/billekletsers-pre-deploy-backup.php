<?php
/**
 * Plugin Name: Billekletsers Pre-deploy Backup
 * Description: WP-CLI commando "wp billekletsers backup" dat een volledige UpdraftPlus back-up (bestanden + database) start via UpdraftPlus's eigen "Nu back-uppen"-mechanisme (dezelfde functie als de knop in wp-admin), bedoeld om vlak vóór het wegschrijven van nieuwe plugin/thema-code te draaien. Wacht daarna tot de back-up daadwerkelijk in de UpdraftPlus back-uphistorie verschijnt voordat het commando succes meldt.
 * Author: Voor C.V. De Billekletsers
 * Version: 1.0.0
 *
 * Werking (geverifieerd tegen de broncode van UpdraftPlus, versie zoals geïnstalleerd op deze site):
 * - UpdraftPlus_Admin::request_backupnow() is exact de functie die de "Back-up nu"-knop in
 *   wp-admin aanroept; die vuurt de action 'updraft_backupnow_backup_all' af, wat de start
 *   is van een volledige back-up (bestanden + database).
 * - UpdraftPlus laadt zijn admin.php (en dus $updraftplus_admin) normaal pas via de
 *   'admin_menu'-hook, die onder WP-CLI niet afgaat. Daarom laden we admin.php hier expliciet
 *   met UpdraftPlus's eigen 'updraft_try_include_file()'-functie.
 * - Een back-up draait in stappen via WP-Cron; met "wp cron event run --due-now" duwen we die
 *   stappen vooruit totdat de back-up terug te vinden is in UpdraftPlus_Backup_History::get_history(),
 *   waar elke voltooide back-up een 'nonce' bevat die overeenkomt met de nonce van de gestarte job.
 */

if (!defined('ABSPATH')) exit;
if (!defined('WP_CLI') || !WP_CLI) return;

WP_CLI::add_command('billekletsers backup', function ($args, $assoc_args) {

	if (!function_exists('updraft_try_include_file') || !defined('UPDRAFTPLUS_DIR')) {
		WP_CLI::error('UpdraftPlus is niet actief op deze site.');
	}

	global $updraftplus_admin;
	if (empty($updraftplus_admin)) {
		updraft_try_include_file('admin.php', 'include_once');
	}
	if (empty($updraftplus_admin) || !class_exists('UpdraftPlus_Backup_History')) {
		WP_CLI::error('Kon UpdraftPlus niet initialiseren.');
	}

	$target_nonce = null;
	$start_error = null;

	$updraftplus_admin->request_backupnow(array(), function ($msg) use (&$target_nonce, &$start_error) {
		if (!empty($msg['error'])) {
			$start_error = $msg['error'];
			return;
		}
		$target_nonce = isset($msg['nonce']) ? $msg['nonce'] : null;
	});

	if ($start_error) {
		WP_CLI::error('UpdraftPlus meldde een fout bij het starten: ' . $start_error);
	}
	if (!$target_nonce) {
		WP_CLI::error('Kon de back-up niet starten (geen nonce ontvangen van UpdraftPlus).');
	}

	WP_CLI::log("Back-up gestart bij UpdraftPlus (nonce: {$target_nonce}). Bezig met wachten tot voltooid...");

	$timeout = isset($assoc_args['timeout']) ? (int) $assoc_args['timeout'] : 600;
	$interval = 5;
	$waited = 0;

	while ($waited < $timeout) {
		WP_CLI::runcommand('cron event run --due-now', array(
			'launch' => false,
			'exit_error' => false,
		));

		sleep($interval);
		$waited += $interval;

		foreach (UpdraftPlus_Backup_History::get_history() as $entry) {
			if (!isset($entry['nonce']) || $entry['nonce'] !== $target_nonce) continue;
			// Alleen als voltooid tellen als er ook echt een databasebestand bij zit; anders is
			// het een halve/mislukte back-up en willen we dat niet als succes melden.
			if (empty($entry['db'])) {
				WP_CLI::error("Back-up teruggevonden (nonce: {$target_nonce}), maar zonder databasebestand — waarschijnlijk mislukt. Controleer UpdraftPlus > Back-ups.");
			}
			WP_CLI::success("Back-up voltooid na {$waited} seconden (inclusief database).");
			return;
		}
	}

	WP_CLI::warning("Back-up nog niet bevestigd afgerond na {$timeout}s. Controleer handmatig in wp-admin onder UpdraftPlus > Back-ups voordat je nieuwe code wegschrijft. (nonce: {$target_nonce})");
});
