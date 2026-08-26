<?php
/**
 * Elementor widget classes. Loaded only when Elementor is present.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class Widget_Search extends Widget_Base {

	public function get_name(): string {
		return 'sm_standing_search';
	}

	public function get_title(): string {
		return 'Standing: check a school';
	}

	public function get_icon(): string {
		return 'eicon-search';
	}

	public function get_categories(): array {
		return [ 'sm-standing' ];
	}

	public function get_keywords(): array {
		return [ 'standing', 'register', 'search', 'school', 'cricos', 'dli', 'sponsor' ];
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => 'Content' ] );

		$this->add_control(
			'heading',
			[
				'label'   => 'Heading',
				'type'    => Controls_Manager::TEXT,
				'default' => 'Check where your school stands',
			]
		);

		$this->add_control(
			'country',
			[
				'label'       => 'Limit to one country',
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => $this->country_options(),
				'description' => 'Leave blank to search every register we hold.',
			]
		);

		$this->end_controls_section();
	}

	private function country_options(): array {
		$out = [ '' => 'All countries' ];
		foreach ( Data::instance()->countries() as $c ) {
			$out[ Data::instance()->slug( $c['country'] ) ] = $c['country'];
		}
		return $out;
	}

	protected function render(): void {
		$s = $this->get_settings_for_display();
		echo Elementor::instance()->sc_search(
			[ 'heading' => $s['heading'] ?? '', 'country' => $s['country'] ?? '' ]
		);
	}
}

class Widget_Changes extends Widget_Base {

	public function get_name(): string {
		return 'sm_standing_changes';
	}

	public function get_title(): string {
		return 'Standing: recent changes';
	}

	public function get_icon(): string {
		return 'eicon-post-list';
	}

	public function get_categories(): array {
		return [ 'sm-standing' ];
	}

	public function get_keywords(): array {
		return [ 'standing', 'register', 'changes', 'delisted', 'removed' ];
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => 'Content' ] );

		$this->add_control( 'heading', [ 'label' => 'Heading', 'type' => Controls_Manager::TEXT, 'default' => 'What changed recently' ] );

		$this->add_control(
			'country',
			[
				'label'   => 'Country',
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => ( function () {
					$out = [ '' => 'All countries' ];
					foreach ( Data::instance()->countries() as $c ) {
						$out[ Data::instance()->slug( $c['country'] ) ] = $c['country'];
					}
					return $out;
				} )(),
			]
		);

		$this->add_control(
			'kind',
			[
				'label'   => 'Only show',
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => [
					''         => 'Every kind of change',
					'removed'  => 'No longer listed',
					'added'    => 'Newly listed',
					'renamed'  => 'Name changed',
					'modified' => 'Record changed',
				],
			]
		);

		$this->add_control(
			'limit',
			[
				'label'   => 'How many',
				'type'    => Controls_Manager::NUMBER,
				'default' => 5,
				'min'     => 1,
				'max'     => 50,
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$s = $this->get_settings_for_display();
		echo Elementor::instance()->sc_changes(
			[
				'heading' => $s['heading'] ?? '',
				'country' => $s['country'] ?? '',
				'kind'    => $s['kind'] ?? '',
				'limit'   => $s['limit'] ?? 5,
			]
		);
	}
}

class Widget_Stats extends Widget_Base {

	public function get_name(): string {
		return 'sm_standing_stats';
	}

	public function get_title(): string {
		return 'Standing: coverage stats';
	}

	public function get_icon(): string {
		return 'eicon-counter';
	}

	public function get_categories(): array {
		return [ 'sm-standing' ];
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => 'Content' ] );
		$this->add_control(
			'country',
			[
				'label'   => 'Country',
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => ( function () {
					$out = [ '' => 'Whole register' ];
					foreach ( Data::instance()->countries() as $c ) {
						$out[ Data::instance()->slug( $c['country'] ) ] = $c['country'];
					}
					return $out;
				} )(),
			]
		);
		$this->end_controls_section();
	}

	protected function render(): void {
		$s = $this->get_settings_for_display();
		echo Elementor::instance()->sc_stats( [ 'country' => $s['country'] ?? '' ] );
	}
}
