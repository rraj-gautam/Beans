<?php
/**
 * This class provides the means to add Post Meta boxes.
 *
 * @package Beans\Framework\Api\Post_Meta
 *
 * @since 1.0.0
 */

/**
 * Handle the Beans Post Meta workflow.
 *
 * @since 1.0.0
 * @ignore
 * @access private
 *
 * @package Beans\Framework\API\Post_Meta
 */
final class _Beans_Post_Meta
{

    /**
     * Metabox arguments.
     *
     * @var array
     */
    private $args = array();

    /**
     * Fields section.
     *
     * @var string
     */
    private $section;

    /**
     * Constructor.
     *
     * @param string $section Field section.
     * @param array  $args Arguments of the field.
     */
    public function __construct($section, $args)
    {
        $defaults = array(
            'title'    => __('Undefined', 'tm-beans'),
            'context'  => 'normal',
            'priority' => 'high',
        );

        $this->section = $section;
        $this->args    = array_merge($defaults, $args);
        $this->do_once();

        add_action('add_meta_boxes', array( $this, 'register_metabox' ));
        // DISNEL: This is the hook that can be used when saving meta with gutenberg editor
        add_action('transition_post_status', array( $this, 'transition_post_status' ), 10, 3);
    }

    /**
     * Trigger actions only once.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function do_once()
    {
        static $did_once = false;

        if ($did_once) {
            return;
        }

        add_action('edit_form_top', array( $this, 'render_nonce' ));
        add_filter('attachment_fields_to_save', array( $this, 'save_attachment' ));

        $did_once = true;
    }

    /**
     * Fired when a Post's status transitions.
     *
     * Called by WordPress when wp_insert_post() is called.
     *
     * As wp_insert_post() is called by WordPress and the REST API whenever creating or updating a Post,
     * we can safely rely on this hook.
     *
     * @since   1.0.0
     *
     * @param   string      $new_status     New Status
     * @param   string      $old_status     Old Status
     * @param   WP_Post     $post           Post
     */
    public function transition_post_status($new_status, $old_status, $post)
    {
        // Bail if the Post Type isn't public
        // This prevents the rest of this routine running on e.g. ACF Free, when saving Fields (which results in Field loss)
        // DISNEL: For some reason, posts are seen as revisions, so they must be included here
        $post_types = array( 'post', 'page', 'revision' );
        if (! in_array($post->post_type, $post_types)) {
            return;
        }
        // Bail if we're working on a draft or trashed item
        if ($new_status == 'auto-draft' || $new_status == 'draft' || $new_status == 'inherit' || $new_status == 'trash') {
            return;
        }
        /**
         * = REST API =
         * If this is a REST API Request, we can't use the wp_insert_post action, because any metadata
         * included in the REST API request is *not* included in the call to wp_insert_post().
         *
         * Instead, we must use a late REST API action that gives the REST API time to save metadata.
         *
         * Thankfully, the REST API supplies an action to do this: rest_after_insert_posttype, where posttype
         * is the Post Type in question.
         *
         * Note that any meta being supplied in the REST API Request MUST be registered with WordPress using
         * register_meta().  If you're using a third party plugin to register custom fields, you'll need to
         * confirm it uses register_meta() as part of its process.
         *
         * = Gutenberg =
         * If Gutenberg is being used on the given Post Type, two requests are sent:
         * - a REST API request, comprising of Post Data and Metadata registered *in* Gutenberg,
         * - a standard request, comprising of Post Metadata registered *outside* of Gutenberg (i.e. add_meta_box() data)
         *
         * If we're publishing a Post, the second request will be seen by transition_post_status() as an update, which
         * isn't strictly true.
         *
         * Therefore, we set a meta flag on the first Gutenberg REST API request to defer acting on the Post until
         * the second, standard request - at which point, all Post metadata will be available to the Plugin.
         *
         * = Classic Editor =
         * Metadata is included in the call to wp_insert_post(), meaning that it's saved to the Post before we use it.
         */
        // Flag to determine if the current Post is a Gutenberg Post
        $is_gutenberg_post = $this->is_gutenberg_post($post);
        // If a previous request flagged that an 'update' request should be treated as a publish request (i.e.
        // we're using Gutenberg and request to post.php was made after the REST API), do this now.
        $needs_publishing = get_post_meta($post->ID, '_needs_publishing', true);
        if ($needs_publishing) {
            // Run Publish Status Action now
            delete_post_meta($post->ID, '_needs_publishing');
            add_action('wp_insert_post', array( $this, 'wp_insert_post_publish' ), 999);
            // Don't need to do anything else, so exit
            return;
        }
        // If a previous request flagged that an update request be deferred (i.e.
        // we're using Gutenberg and request to post.php was made after the REST API), do this now.
        $needs_updating = get_post_meta($post->ID, '_needs_updating', true);
        if ($needs_updating) {
            // Run Publish Status Action now
            delete_post_meta($post->ID, '_needs_updating');
            add_action('wp_insert_post', array( $this, 'wp_insert_post_update' ), 999);
            // Don't need to do anything else, so exit
            return;
        }
        // Publish
        if ($new_status == 'publish' && $new_status != $old_status) {
            /**
             * Classic Editor
             */
            if (! defined('REST_REQUEST') || (defined('REST_REQUEST') && ! REST_REQUEST)) {
                add_action('wp_insert_post', array( $this, 'wp_insert_post_publish' ), 999);
                // Don't need to do anything else, so exit
                return;
            }
            /**
             * Gutenberg Editor
             * - Non-Gutenberg metaboxes are POSTed via a second, separate request to post.php, which appears
             * as an 'update'.  Define a meta key that we'll check on the separate request later.
             */
            if ($is_gutenberg_post) {
                update_post_meta($post->ID, '_needs_publishing', 1);

                // Don't need to do anything else, so exit
                return;
            }
            /**
             * REST API
             */
            add_action('rest_after_insert_' . $post->post_type, array( $this, 'rest_api_post_publish' ), 10, 2);
            // Don't need to do anything else, so exit
            return;
        }
        // Update
        if ($new_status == 'publish' && $old_status == 'publish') {
            /**
             * Classic Editor
             */
            if (! defined('REST_REQUEST') || (defined('REST_REQUEST') && ! REST_REQUEST)) {
                add_action('wp_insert_post', array( $this, 'wp_insert_post_update' ), 999);
                // Don't need to do anything else, so exit
                return;
            }
            /**
             * Gutenberg Editor
             * - Non-Gutenberg metaboxes are POSTed via a second, separate request to post.php, which appears
             * as an 'update'.  Define a meta key that we'll check on the separate request later.
             */
            if ($is_gutenberg_post) {
                update_post_meta($post->ID, '_needs_updating', 1);

                // Don't need to do anything else, so exit
                return;
            }
            /**
             * REST API
             */
            add_action('rest_after_insert_' . $post->post_type, array( $this, 'rest_api_post_update' ), 10, 2);
            // Don't need to do anything else, so exit
            return;
        }
    }
    /**
     * Helper function to determine if the Post is using the Gutenberg Editor.
     *
     * @since   1.0.0
     *
     * @param   WP_Post     $post   Post
     * @return  bool                Post uses Gutenberg Editor
     */
    private function is_gutenberg_post($post)
    {
        // This will fail if a Post is created or updated with no content and only a title.
        if (strpos($post->post_content, '<!-- wp:') === false) {
            return false;
        }
        return true;
    }
    /**
     * Called when a Post has been Published via the REST API
     *
     * @since   1.0.0
     *
     * @param   WP_Post             $post           Post
     * @param   WP_REST_Request     $request        Request Object
     */
    public function rest_api_post_publish($post, $request)
    {
        $this->wp_insert_post_publish($post->ID);
    }
    /**
     * Called when a Post has been Published via the REST API
     *
     * @since   1.0.0
     *
     * @param   WP_Post             $post           Post
     * @param   WP_REST_Request     $request        Request Object
     */
    public function rest_api_post_update($post, $request)
    {
        $this->wp_insert_post_update($post->ID);
    }
    /**
     * Called when a Post has been Published
     *
     * @since   1.0.0
     *
     * @param   int     $post_id    Post ID
     */
    public function wp_insert_post_publish($post_id)
    {
        // Call main function
        $this->send($post_id, 'publish');
    }
    /**
     * Called when a Post has been Updated
     *
     * @since   1.0.0
     *
     * @param   int     $post_id    Post ID
     */
    public function wp_insert_post_update($post_id)
    {
        // Call main function
        $this->send($post_id, 'update');
    }
    /**
     * Main function. Called when any Page, Post or CPT is published or updated
     *
     * @since   1.0.0
     *
     * @param   int         $post_id                Post ID
     * @param   string      $action                 Action (publish|update)
     * @return  mixed                               WP_Error | API Results array
     */
    public function send($post_id, $action)
    {
        // Get Post
        //global $post;
        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('no_post', sprintf(__('No WordPress Post could be found for Post ID %s'), $post_id));
        }
        // @TODO Save any metadata that your Plugin expects now - such as post-specific settings your Plugin may offer via add_meta_box() calls
        //update_post_meta($post_id, 'your-key', sanitize_text_field($_POST['your-key']));
        // DISNEL: this is the functions that was called from the save_post hook
        $this->save($post_id);

        // @TODO Add your code here to send your Post to whichever API / third party service
    }


    /**
     * Render post meta nonce.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_nonce()
    {
        include dirname(__FILE__) . '/views/nonce.php';
    }

    /**
     * Add the Metabox.
     *
     * @since 1.0.0
     *
     * @param string $post_type Name of the post type.
     *
     * @return void
     */
    public function register_metabox($post_type)
    {
        add_meta_box(
            $this->section,
            $this->args['title'],
            array( $this, 'render_metabox_content' ),
            $post_type,
            $this->args['context'],
            $this->args['priority']
        );
    }

    /**
     * Render metabox content.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_metabox_content()
    {
        // DISNEL: Arrange the necessary data fist to register the meta with beans_do_register_term_meta
        $post_type = get_post_type();

        $defaults = array(
                        'object_subtype'		=> $post_type,
                        'type'              => 'string',
                        'description'       => '',
                        'single'            => true,
                        'sanitize_callback' => null,
                        'auth_callback'     => null,
                        'show_in_rest'      => true,
                );

        $fields = beans_get_fields('post_meta', $this->section);
        foreach ($fields as $field) {
            // DISNEL: This is an important part, since the meta needs to be registered this way
            register_meta('post', $field['id'], $defaults);
            beans_field($field);
        }
    }

    /**
     * Save Post Meta.
     *
     * @since 1.0.0
     *
     * @param int $post_id Post ID.
     *
     * @return mixed
     */
    public function save($post_id)
    {
        if (_beans_doing_autosave()) {
            return false;
        }

        $fields = beans_post('beans_fields');

        if (! $this->ok_to_save($post_id, $fields)) {
            return $post_id;
        }

        foreach ($fields as $field => $value) {
            update_post_meta($post_id, $field, $value);
        }
    }




    /**
     * Save Post Meta for attachment.
     *
     * @since 1.0.0
     *
     * @param array $attachment Attachment data.
     *
     * @return mixed
     */
    public function save_attachment($attachment)
    {
        if (_beans_doing_autosave()) {
            return $attachment;
        }

        $fields = beans_post('beans_fields');

        if (! $this->ok_to_save($attachment['ID'], $fields)) {
            return $attachment;
        }

        foreach ($fields as $field => $value) {
            update_post_meta($attachment['ID'], $field, $value);
        }

        return $attachment;
    }

    /**
     * Check if all criteria are met to safely save post meta.
     *
     * @param int   $id The Post Id.
     * @param array $fields The array of fields to save.
     *
     * @return bool
     */
    public function ok_to_save($id, $fields)
    {
        // DISNEL: This has to remain comment, there's some issue with the nonce still not clarified enough

        /*if (! wp_verify_nonce(beans_post('beans_post_meta_nonce'), 'beans_post_meta_nonce')) {
            return false;
        }*/

        if (! current_user_can('edit_post', $id)) {
            return false;
        }

        return ! empty($fields);
    }
}
