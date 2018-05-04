<?php
/**
 * Tests for _Beans_Term_Meta::construct()
 *
 * @package Beans\Framework\Tests\Integration\API\Term_Meta
 *
 * @since   1.5.0
 */

namespace Beans\Framework\Tests\Integration\API\Term_Meta;

use Beans\Framework\Tests\Integration\API\Term_Meta\Includes\Beans_Term_Meta_Test_Case;
use _Beans_Term_Meta;

require_once BEANS_THEME_DIR . '/lib/api/term-meta/class-beans-term-meta.php';
require_once dirname( __DIR__ ) . '/includes/class-beans-term-meta-test-case.php';

/**
 * Class Tests_BeansTermMeta_Construct
 *
 * @package Beans\Framework\Tests\Integration\API\Term_Meta
 * @group   api
 * @group   api-term-meta
 */
class Tests_BeansTermMeta_Construct extends Beans_Term_Meta_Test_Case {

	/**
	 * Tests __construct should set correct hooks when class is instantiated.
	 */
	public function test_should_set_correct_hooks_when_class_is_instantiated() {
		$_GET['taxonomy'] = 'sample-taxonomy';

		// First instantiation sets all hooks.
		$term_meta1 = new _Beans_Term_Meta( 'tm-beans' );

		$this->assertEquals( 10, has_action( 'sample-taxonomy_edit_form', array( $term_meta1, 'render_nonce' ) ) );
		$this->assertEquals( 10, has_action( 'edit_term', array( $term_meta1, 'save' ) ) );
		$this->assertEquals( 10, has_action( 'delete_term', array( $term_meta1, 'delete' ) ) );
		$this->assertEquals( 10, has_action( 'sample-taxonomy_edit_form_fields', array( $term_meta1, 'render_fields' ) ) );

		// Subsequent instantiation sets only {$taxonomy}_edit_form_fields hook.
		$term_meta2 = new _Beans_Term_Meta( 'tm-beans-child' );
		$this->assertFalse( has_action( 'sample-taxonomy_edit_form', array( $term_meta2, 'render_nonce' ) ) );
		$this->assertFalse( has_action( 'edit_term', array( $term_meta2, 'save' ) ) );
		$this->assertFalse( has_action( 'delete_term', array( $term_meta2, 'delete' ) ) );
		$this->assertEquals( 10, has_action( 'sample-taxonomy_edit_form_fields', array( $term_meta2, 'render_fields' ) ) );
	}
}
