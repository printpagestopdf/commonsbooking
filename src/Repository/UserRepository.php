<?php


namespace CommonsBooking\Repository;


use CommonsBooking\Plugin;
use WP_Query;

class UserRepository {

	const USER_CACHE_TAG = 'users';

	/**
	 * Returns all users with cb manager role.
	 * Cached to improve backend performance.
	 * @return mixed
	 */
	public static function getCBManagers() {
		if ( Plugin::getCacheItem() ) {
			return Plugin::getCacheItem();
		}
		else {
			$cbManagers = get_users( [ 'role__in' => [ Plugin::$CB_MANAGER_ID ] ] );
			Plugin::setCacheItem( $cbManagers, [ self::USER_CACHE_TAG ] );
			return $cbManagers;
		}

	}

	public static function clearUserCache() {
		Plugin::clearCache( [ self::USER_CACHE_TAG ] );
	}

	/**
	 * Returns all users with items/locations.
	 * @return array
	 */
	public static function getOwners(): array {
		if ( Plugin::getCacheItem() ) {
			return Plugin::getCacheItem();
		}
		else {
			$owners   = [];
			$ownerIds = [];
			$args     = array(
				'post_type' => array(
					\CommonsBooking\Wordpress\CustomPostType\Item::$postType,
					\CommonsBooking\Wordpress\CustomPostType\Location::$postType,
				)
			);
			$query    = new WP_Query( $args );
			if ( $query->have_posts() ) {
				$cbPosts = $query->get_posts();
				foreach ( $cbPosts as $cbPost ) {
					$ownerIds[]       = $cbPost->post_author;
					$additionalAdmins = get_post_meta( $cbPost->ID, '_' . $cbPost->post_type . '_admins', true );
					if ( is_array( $additionalAdmins ) && count( $additionalAdmins ) ) {
						$ownerIds = array_merge( $ownerIds, $additionalAdmins );
					}
				}
			}
			$ownerIds = array_unique( $ownerIds );
			if ( count( $ownerIds ) ) {
				return get_users(
					array( 'include' => $ownerIds )
				);
			}

			Plugin::setCacheItem( $owners, [ self::USER_CACHE_TAG ]);
			return $owners;
		}

	}

	/**
	 * Returns an array of all User Roles as roleID => translated role name
	 *
	 * @return array
	 */
	public static function getUserRoles(): array {
		if (Plugin::getCacheItem() ) {
			return Plugin::getCacheItem();
		}
		else {
			global $wp_roles;
			$rolesArray = $wp_roles->roles;
			$roles      = [];
			foreach ( $rolesArray as $roleID => $value ) {
				$roles[ $roleID ] = translate_user_role( $value['name'] );
			}
			Plugin::setCacheItem( $roles, [ self::USER_CACHE_TAG ] );
			return $roles;
		}
	}

}
