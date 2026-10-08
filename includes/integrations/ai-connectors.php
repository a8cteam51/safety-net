<?php
/**
 * AI connectors: deletes the AI provider keys WordPress, the AI plugin and AI provider plugins store, without a backup, and deactivates those plugins
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\AIConnectors;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'ai-connectors',
			label: 'AI providers',
			plugins: array(
				'ai/ai.php',
				'ai-engine',
				'ai-provider-for-',
				'bestony-ai-provider/',
				'birbwhale/',
				'duetg-ai-connector/',
				'duoport-connect-for-opencode/',
				'jokiruiz-local-model-connector/',
				'koneek-multi-provider-ai-gateway/',
				'latentkit-ai-provider/',
				'mittwald-ai-provider/',
				'modeltrestle-ai-connector-for-nano-gpt/',
				'mw-local-ai-connector/',
				'mwai',
				'onmyodev-connector-for-deepseek/',
				'opencode-ai-provider/',
				'razhur-connector-for-avalai/',
				'sync-to-gpt',
				'ultimate-ai-connector-compatible-endpoints/',
				'vercel-ai-gateway-provider/',
				'zactonz-ai-',
			),
			options: array(
				'mwai_options',
				'mwai_v2_options',
			),
			// ai-provider-for-exo's aiprfoex_api_key is kept: it is an optional token for a model cluster the site owner runs locally.
			delete_options: array(
				'halawa_chatgpt_tokens',
				'jokiruiz_local_model_connector_api_key',
				'mwlai_actual_computer_api_key',
				'mwlai_api_key',
				'ultimate_ai_connector_api_key',
				'wp_ai_client_provider_credentials',
				'zctz_ollama_ai_connector_cloud_api_key',
				'zctz_ollama_ai_connector_self_hosted_api_key',
			),
			// These also hold the provider's other settings, so only the secrets inside them are blanked.
			delete_partial_options: array(
				'ai_provider_for_cursor_settings'     => array( 'api_key' ),
				'aipcf_settings'                      => array( 'api_key', 'gateway_token', 'qdrant_api_key', 'pg_password' ),
				'obenweb_openwebui_provider_settings' => array( 'api_key' ),
				'ultimate_ai_connector_providers'     => array( 'api_key' ),
				'vercel_ai_gateway_provider_settings' => array( 'api_key' ),
				'wp_ai_client_credentials'            => array( 'api_key' ),
			),
			delete_option_prefixes: array(
				'connectors_ai_' => array( '_api_key', '_application_password' ),
				'_secret_ai/'    => array( '_api_key' ),
				'koneek_api_key',
				'zctz_openrouter_secret_',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
