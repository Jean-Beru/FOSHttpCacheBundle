<?php

namespace FOS\HttpCacheBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('fos_http_cache');

        $rootNode
            ->children()
                ->booleanNode('debug')
                    ->defaultValue('%kernel.debug%')
                    ->info('Whether to send a debug header with the response to trigger a caching proxy to send debug information.')
                ->end()
                ->scalarNode('debug_header')
                    ->defaultValue('X-Cache-Debug')
                    ->info('The header to send if debug is true.')
                ->end()
            ->end()
        ;

        $this->addUserContextListenerSection($rootNode);
        $this->addRulesSection($rootNode);
        $this->addProxyClientSection($rootNode);
        $this->addTagListenerSection($rootNode);
        $this->addFlashMessageListenerSection($rootNode);
        $this->addInvalidatorsSection($rootNode);

        return $treeBuilder;
    }

    private function addUserContextListenerSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('user_identifier_header')
            ->children()
                ->arrayNode('user_context')
                    ->info('Listener that returns the request for the user context hash as early as possible.')
                    ->addDefaultsIfNotSet()
                    ->canBeEnabled()
                    ->children()
                        ->arrayNode('match')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('matcher_service')
                                    ->defaultValue('fos_http_cache.user_context.request_matcher')
                                    ->info('Service id of a request matcher that tells whether the request is a context hash request.')
                                ->end()
                                ->scalarNode('accept')
                                    ->defaultValue('application/vnd.fos.user-context-hash')
                                    ->info('Specify the accept HTTP header used for context hash requests.')
                                ->end()
                                ->scalarNode('method')
                                    ->defaultNull()
                                    ->info('Specify the HTTP method used for context hash requests.')
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('hash_cache_ttl')
                            ->defaultValue(0)
                            ->info('Cache the response for the hash for the specified number of seconds. Setting this to 0 will not cache those responses at all.')
                        ->end()
                        ->arrayNode('user_identifier_headers')
                            ->prototype('scalar')->end()
                            ->defaultValue(array('Cookie', 'Authorization'))
                            ->info('List of headers that contains the unique identifier for the user in the hash request.')
                        ->end()
                        ->scalarNode('user_hash_header')
                            ->defaultValue('X-User-Context-Hash')
                            ->info('Name of the header that contains the hash information for the context.')
                        ->end()
                        ->booleanNode('role_provider')
                            ->defaultFalse()
                            ->info('Whether to enable a provider that automatically adds all roles of the current user to the context.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addRulesSection(ArrayNodeDefinition $rootNode)
    {
        $rules = $rootNode
            ->fixXmlConfig('rule')
            ->children()
                ->arrayNode('rules')
                    ->prototype('array')
                        ->children();

        $this->addMatchSection($rules);
        $this->addHeaderSection($rules);
        $rules
            ->arrayNode('tags')
                ->prototype('scalar')
                ->validate()
                    ->ifTrue(function ($v) {return !count($v);})
                    ->thenUnset()
                ->end()
                ->info('Tags to add to the response on safe requests, to invalidate on unsafe requests')
            ->end()
        ;
    }

    private function addMatchSection(NodeBuilder $rules)
    {
        $rules
            ->arrayNode('match')
                ->cannotBeOverwritten()
                ->isRequired()
                ->fixXmlConfig('method')
                ->validate()
                    ->ifTrue(function ($v) {return !empty($v['additional_cacheable_status']) && !empty($v['match_response']);})
                    ->thenInvalid('You may not set both additional_cacheable_status and match_response')
                ->end()
                ->children()
                    ->scalarNode('path')
                        ->defaultNull()
                        ->info('Request path.')
                    ->end()
                    ->scalarNode('host')
                        ->defaultNull()
                        ->info('Request host name.')
                    ->end()
                    ->arrayNode('methods')
                        ->beforeNormalization()->ifString()->then(function($v) { return preg_split('/\s*,\s*/', $v); })->end()
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                        ->info('Request HTTP methods.')
                    ->end()
                    ->arrayNode('ips')
                        ->beforeNormalization()->ifString()->then(function($v) { return preg_split('/\s*,\s*/', $v); })->end()
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                        ->info('List of client IPs.')
                    ->end()
                    ->arrayNode('attributes')
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                        ->info('Regular expressions on request attributes.')
                    ->end()
                    ->arrayNode('additional_cacheable_status')
                        ->prototype('scalar')->end()
                        ->info('Additional response HTTP status codes that will get the headers of this rule.')
                    ->end()
                    ->scalarNode('match_response')
                        ->defaultValue(array())
                        ->info('Expression to decide whether response should be matched. Replaces HTTP code check and additional_cacheable_status.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function addHeaderSection(NodeBuilder $rules)
    {
        $rules
            ->arrayNode('headers')
                ->treatNullLike(array())
                ->children()
                    ->arrayNode('cache_control')
                        ->useAttributeAsKey('name')
                        ->prototype('scalar')->end()
                        ->info('Add the specified cache control directives.')
                    ->end()
                    ->scalarNode('last_modified')
                        ->validate()
                            ->ifString()
                            ->then(function ($v) {new \DateTime($v);})
                        ->end()
                        ->info('Set a default last modified timestamp if none is set yet. Value must be parseable by DateTime')
                    ->end()
                    ->scalarNode('reverse_proxy_ttl')
                        ->defaultNull()
                        ->info('Specify an X-Reverse-Proxy-TTL header with a time in seconds for a caching proxy under your control.')
                    ->end()
                    ->arrayNode('vary')
                        ->beforeNormalization()->ifString()->then(function($v) { return preg_split('/\s*,\s*/', $v); })->end()
                        ->prototype('scalar')->end()
                        ->info('Define a list of additional headers on which the response varies.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function addProxyClientSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('proxy_client')
                    ->children()
                        ->enumNode('default')
                            ->values(array('varnish', 'nginx'))
                            ->info('If you configure more than one proxy client, specify which client is the default.')
                        ->end()
                        ->arrayNode('varnish')
                            ->children()
                                ->arrayNode('servers')
                                    ->beforeNormalization()->ifString()->then(function($v) { return preg_split('/\s*,\s*/', $v); })->end()
                                    ->useAttributeAsKey('name')
                                    ->isRequired()
                                    ->requiresAtLeastOneElement()
                                    ->prototype('scalar')->end()
                                    ->info('Addresses of the hosts varnish is running on. May be hostname or ip, and with :port if not the default port 6081.')
                                ->end()
                                ->scalarNode('base_url')
                                    ->defaultNull()
                                    ->info('Default host name and optional path for path based invalidation.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addTagListenerSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('tag_listener')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('enabled')
                            ->values(array(true, false, 'auto'))
                            ->defaultValue('auto')
                            ->info('Allows to disable the listener for tag annotations when your project does not use the annotations. Enabled by default if you configure a proxy client.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addFlashMessageListenerSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('flash_message_listener')
                    ->canBeUnset()
                    ->canBeEnabled()
                    ->info('Activate the flash message listener that puts flash messages into a cookie.')
                    ->children()
                        ->scalarNode('name')
                            ->defaultValue('flashes')
                            ->info('Name of the cookie to set for flashes.')
                        ->end()
                        ->scalarNode('path')
                            ->defaultValue('/')
                            ->info('Cookie path validity.')
                        ->end()
                        ->scalarNode('host')
                            ->defaultNull()
                            ->info('Cookie host name validity.')
                        ->end()
                        ->scalarNode('secure')
                            ->defaultFalse()
                            ->info('Whether the cookie should only be transmitted over a secure HTTPS connection from the client.')
                        ->end()
                        ->scalarNode('httpOnly')
                            ->defaultTrue()
                            ->info('Whether the cookie will be made accessible only through the HTTP protocol.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addInvalidatorsSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('invalidators')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->arrayNode('origin_routes')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->prototype('scalar')->end()
                            ->end()
                            ->arrayNode('invalidate_routes')
                                ->useAttributeAsKey('name')
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode('parameter_mapper')->end()
                                        ->booleanNode('ignore_extra_params')->defaultTrue()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();
    }
}
