<?php

namespace App\Services\Hotel;

use App\Common\FilterException;
use App\Common\SingletonTrait;
use App\Common\PDOSingleton;
use App\Entities\HotelEntity;
use App\Entities\RoomEntity;
use App\Services\Room\RoomService;
use App\Common\Timers;
use Exception;
use PDO;


class OneRequestHotelService extends AbstractHotelService
{

    protected function convertEntityFromArray ( array $data, array $args ) : HotelEntity {

        $hotel = ( new HotelEntity() )
            ->setId( $data['ID'] )
            ->setName( $data['display_name'] );

        $timer = Timers::getInstance();
        $timer->start( 'getRooms' );

        $sqlArgs = [];

        $sqlQuery="SELECT
        usermeta.meta_key,
        usermeta.meta_value,
        ROUND(AVG(postmeta.meta_value)) AS rating,
        COUNT(postmeta.meta_value) AS count
        FROM wp_usermeta as usermeta, wp_posts as post, wp_postmeta as postmeta
        WHERE usermeta.user_id = :userId AND post.post_author = :hotelId AND post.ID = postmeta.post_id AND postmeta.meta_key = 'rating' And post.post_type = 'review'";

        $sqlQuery ="SELECT POST.ID as id, ".
            "POST.post_title as title, ".
            "surfaceData.meta_value as surface, ".
            "MIN(priceData.meta_value) as price, ".
            "roomsData.meta_value as rooms, ".
            "bathroomsData.meta_value as bathrooms, ".
            "typeData.meta_value as type, ".
            "FROM wp_posts as POST ";



        // Charge les données meta de l'hôtel
        $metasData = $this->getMetas( $hotel );
        $hotel->setAddress( $metasData['address'] );
        $hotel->setGeoLat( $metasData['geo_lat'] );
        $hotel->setGeoLng( $metasData['geo_lng'] );
        $hotel->setImageUrl( $metasData['coverImage'] );
        $hotel->setPhone( $metasData['phone'] );

        // Définit la note moyenne et le nombre d'avis de l'hôtel
        $reviewsData = $this->getReviews( $hotel );
        $hotel->setRating( $reviewsData['rating'] );
        $hotel->setRatingCount( $reviewsData['count'] );

        // Charge la chambre la moins chère de l'hôtel
        $cheapestRoom = $this->getCheapestRoom( $hotel, $args );
        $hotel->setCheapestRoom($cheapestRoom);

        // Verification de la distance
        if ( isset( $args['lat'] ) && isset( $args['lng'] ) && isset( $args['distance'] ) ) {
            $hotel->setDistance( $this->computeDistance(
                floatval( $args['lat'] ),
                floatval( $args['lng'] ),
                floatval( $hotel->getGeoLat() ),
                floatval( $hotel->getGeoLng() )
            ) );

            if ( $hotel->getDistance() > $args['distance'] )
                throw new FilterException( "L'hôtel est en dehors du rayon de recherche" );
        }

        return $hotel;
    }


    /**
     * Retourne une liste de boutiques qui peuvent être filtrées en fonction des paramètres donnés à $args
     *
     * @param array{
     *   search: string | null,
     *   lat: string | null,
     *   lng: string | null,
     *   price: array{min:float | null, max: float | null},
     *   surface: array{min:int | null, max: int | null},
     *   bedrooms: int | null,
     *   bathrooms: int | null,
     *   types: string[]
     * } $args Une liste de paramètres pour filtrer les résultats
     *
     * @return HotelEntity[] La liste des boutiques qui correspondent aux paramètres donnés à args
     * @throws Exception
     */

    public function list(array $args = []): array
    {
        $db = $this->getDB();
        $stmt = $db->prepare("SELECT * FROM wp_users");
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $results[] = $this->convertEntityFromArray($row, $args);
            } catch (FilterException) {
                // Des FilterException peuvent être déclenchées pour exclure certains hotels des résultats
            }
        }


        return $results;
    }
}


?>