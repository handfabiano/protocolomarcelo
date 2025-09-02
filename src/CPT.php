<?php
namespace ProtocoloMunicipal;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Registro do Custom Post Type "protocolo".
 * Seguro e sem alterar o layout.
 */
class CPT
{
    public static function register(): void
    {
        $caps = [
            'edit_post'              => 'edit_protocolo',
            'read_post'              => 'read_protocolo',
            'delete_post'            => 'delete_protocolo',
            'edit_posts'             => 'edit_protocolos',
            'edit_others_posts'      => 'edit_others_protocolos',
            'publish_posts'          => 'publish_protocolos',
            'read_private_posts'     => 'read_private_protocolos',
            'delete_posts'           => 'delete_protocolos',
            'delete_private_posts'   => 'delete_private_protocolos',
            'delete_published_posts' => 'delete_published_protocolos',
            'edit_private_posts'     => 'edit_private_protocolos',
            'edit_published_posts'   => 'edit_published_protocolos',
        ];

        register_post_type('protocolo', [
            'labels' => [
                'name'          => 'Protocolos',
                'singular_name' => 'Protocolo',
                'add_new_item'  => 'Adicionar Protocolo',
                'edit_item'     => 'Editar Protocolo',
                'view_item'     => 'Ver Protocolo',
                'search_items'  => 'Buscar Protocolos',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-portfolio',
            'capability_type'     => ['protocolo','protocolos'],
            'capabilities'        => $caps,
            'map_meta_cap'        => true,
            'supports'            => ['title'],
            'has_archive'         => false,
            'publicly_queryable'  => true,
            'rewrite'             => ['slug' => 'protocolo', 'with_front' => false],
            'show_in_rest'        => true,
        ]);
    }
}
