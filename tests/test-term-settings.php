<?php
/**
 * Tests for the per-term "behavior" settings registry.
 *
 * A behavior is a boolean a term carries in its meta, rendered as a checkbox on the term
 * add/edit form. The interesting part is the save gate: an unchecked checkbox submits nothing,
 * so the form posts a hidden `multisite_term_settings_present` marker and only a submission
 * carrying it may clear a flag. Without that, every programmatic term update would wipe the
 * behaviors of the term it touched.
 *
 * @package multitaxo
 */

/**
 * Registry, defaults, rendering and persistence of term behaviors.
 */
class Test_Term_Settings extends WP_UnitTestCase {

	/**
	 * Taxonomy the behaviors are registered on.
	 *
	 * @var string
	 */
	private $tax = 'behavior_tax';

	/**
	 * The behavior key under test.
	 *
	 * @var string
	 */
	private $key = 'behavior_is_featured';

	/**
	 * Register the taxonomy and one behavior on it.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post' ), array( 'hierarchical' => true ) );
		register_multisite_term_setting(
			$this->tax,
			$this->key,
			array(
				'label'       => 'Featured',
				'description' => 'Show first.',
			)
		);
	}

	/**
	 * Leave no POST state behind for the next test.
	 */
	public function tear_down() {
		unset( $_POST['multisite_term_settings_present'], $_POST[ $this->key ] );
		parent::tear_down();
	}

	/**
	 * Create a term and return its ID.
	 *
	 * @param string $name Term name.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name ): int {
		$res = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $res );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * Registration fills in the defaults and keys the registry by taxonomy.
	 */
	public function test_registration_defaults() {
		register_multisite_term_setting( $this->tax, 'behavior_bare' );

		$settings = get_multisite_term_settings( $this->tax );

		$this->assertArrayHasKey( $this->key, $settings );
		$this->assertSame( 'Featured', $settings[ $this->key ]['label'] );
		$this->assertFalse( $settings[ $this->key ]['default'] );

		$this->assertSame( 'behavior_bare', $settings['behavior_bare']['label'], 'the key doubles as the label' );
		$this->assertSame( 'boolean', $settings['behavior_bare']['type'] );
		$this->assertSame( '', $settings['behavior_bare']['description'] );

		$this->assertSame( array(), get_multisite_term_settings( 'tax_with_no_behaviors' ) );
	}

	/**
	 * Registering the same key twice replaces the args instead of duplicating the setting.
	 */
	public function test_registration_is_idempotent_per_key() {
		$before = count( get_multisite_term_settings( $this->tax ) );

		register_multisite_term_setting( $this->tax, $this->key, array( 'label' => 'Renamed' ) );

		$settings = get_multisite_term_settings( $this->tax );

		$this->assertCount( $before, $settings );
		$this->assertSame( 'Renamed', $settings[ $this->key ]['label'] );
	}

	/**
	 * The first behavior on a taxonomy wires the renderers to that taxonomy's form hooks.
	 */
	public function test_registration_hooks_the_renderers() {
		$this->assertNotFalse( has_action( "{$this->tax}_add_form_fields", 'render_multisite_term_settings_add_fields' ) );
		$this->assertNotFalse( has_action( "{$this->tax}_multisite_edit_form_fields", 'render_multisite_term_settings_edit_fields' ) );
		$this->assertNotFalse( has_action( 'created_multisite_term', 'save_multisite_term_settings' ) );
		$this->assertNotFalse( has_action( 'edited_multisite_term', 'save_multisite_term_settings' ) );
	}

	/**
	 * An unset flag reads as the registered default, and only the string '1' reads as true.
	 */
	public function test_get_setting_reads_meta_with_default() {
		$term_id = $this->make_term( 'Unset' );

		$this->assertFalse( get_multisite_term_setting( $term_id, $this->key ) );
		$this->assertTrue( get_multisite_term_setting( $term_id, $this->key, true ), 'default applies while the meta is absent' );

		update_multisite_term_meta( $term_id, $this->key, '1' );
		$this->assertTrue( get_multisite_term_setting( $term_id, $this->key ) );

		update_multisite_term_meta( $term_id, $this->key, '0' );
		$this->assertFalse( get_multisite_term_setting( $term_id, $this->key, true ), 'a stored value beats the default' );
	}

	/**
	 * Without the hidden marker the save is not ours: a checked box is ignored.
	 */
	public function test_save_ignores_submission_without_the_marker() {
		$term_id             = $this->make_term( 'No marker' );
		$_POST[ $this->key ] = '1';

		save_multisite_term_settings( $term_id, 0, $this->tax );

		$this->assertFalse( get_multisite_term_setting( $term_id, $this->key ) );
	}

	/**
	 * With the marker, a checked box is stored and an unchecked one clears the flag.
	 */
	public function test_save_stores_and_clears_with_the_marker() {
		$term_id = $this->make_term( 'Marked' );

		$_POST['multisite_term_settings_present'] = '1';
		$_POST[ $this->key ]                      = '1';
		save_multisite_term_settings( $term_id, 0, $this->tax );
		$this->assertTrue( get_multisite_term_setting( $term_id, $this->key ) );

		unset( $_POST[ $this->key ] );
		save_multisite_term_settings( $term_id, 0, $this->tax );
		$this->assertFalse( get_multisite_term_setting( $term_id, $this->key ) );
		$this->assertSame( '', get_multisite_term_meta( $term_id, $this->key, true ), 'the row is deleted, not set to 0' );
	}

	/**
	 * A taxonomy without behaviors is left alone even when the marker is posted.
	 */
	public function test_save_skips_taxonomy_without_settings() {
		register_multisite_taxonomy( 'plain_tax', array( 'post' ), array() );
		$res = insert_multisite_term( 'Plain', 'plain_tax', array(), false );

		$_POST['multisite_term_settings_present'] = '1';
		$_POST[ $this->key ]                      = '1';
		save_multisite_term_settings( (int) $res['multisite_term_id'], 0, 'plain_tax' );

		$this->assertFalse( get_multisite_term_setting( (int) $res['multisite_term_id'], $this->key ) );
	}

	/**
	 * Creating a term through the form path persists the behavior, because the save callback
	 * rides the created_multisite_term action.
	 */
	public function test_behavior_persists_through_term_creation() {
		$_POST['multisite_term_settings_present'] = '1';
		$_POST[ $this->key ]                      = '1';

		$term_id = $this->make_term( 'Created with behavior' );

		$this->assertTrue( get_multisite_term_setting( $term_id, $this->key ) );
	}

	/**
	 * The add form prints the marker and an unchecked box; the edit form reflects the stored value.
	 */
	public function test_renderers_print_marker_and_state() {
		$term_id = $this->make_term( 'Rendered' );
		update_multisite_term_meta( $term_id, $this->key, '1' );

		ob_start();
		render_multisite_term_settings_add_fields( $this->tax );
		$add = ob_get_clean();

		$this->assertStringContainsString( 'name="multisite_term_settings_present"', $add );
		$this->assertStringContainsString( 'name="' . $this->key . '"', $add );
		$this->assertStringContainsString( 'Show first.', $add );
		$this->assertStringNotContainsString( 'checked', $add, 'a new term starts at the default' );

		ob_start();
		render_multisite_term_settings_edit_fields( get_multisite_term( $term_id, $this->tax ), get_multisite_taxonomy( $this->tax ) );
		$edit = ob_get_clean();

		$this->assertStringContainsString( 'checked', $edit );

		ob_start();
		render_multisite_term_settings_add_fields( 'tax_with_no_behaviors' );
		$this->assertSame( '', ob_get_clean(), 'nothing renders for a taxonomy without behaviors' );
	}
}
