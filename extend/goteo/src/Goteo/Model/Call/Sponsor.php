<?php

namespace Goteo\Model\Call {

    use Goteo\Library\Check,
        Goteo\Model\Image;


    class Sponsor extends \Goteo\Core\Model {

        //table for this model is not sponsor but call_sponsor
        protected $Table = 'call_sponsor';

        public
            $id,
            $call,
            $name,
            $url,
            $image,
            $order;

        /*
         *  Devuelve datos de un destacado
         */
        public static function get ($id) {
                $sql = static::query("
                    SELECT
                        id,
                        `call`,
                        name,
                        url,
                        image,
                        `order`
                    FROM    call_sponsor
                    WHERE id = :id
                    ", array(':id' => $id));
                $sponsor = $sql->fetchObject(__CLASS__);

                if (!empty($sponsor->image)) {
                    $sponsor->image = Image::get($sponsor->image);
                }

                return $sponsor;
        }

        /*
         * Lista de patrocinadores
         */
        public static function getAll ($call) {

            $list = array();

            $sql = static::query("
                SELECT
                    id,
                    `call`,
                    name,
                    url,
                    image,
                    `order`
                FROM    call_sponsor
                WHERE `call` = :call
                ORDER BY `order` ASC, name ASC
                ", array(':call'=>$call));

            foreach ($sql->fetchAll(\PDO::FETCH_CLASS, __CLASS__) as $sponsor) {
                $list[] = $sponsor;
            }

            return $list;
        }

        /*
         * Lista de patrocinadores
         */
        public static function getList ($call) {

            $list = array();

            $sql = static::query("
                SELECT
                    id,
                    `call`,
                    name,
                    url,
                    image,
                    `order`
                FROM    call_sponsor
                WHERE `call` = :call
                ORDER BY `order` ASC, name ASC
                ", array(':call'=>$call));

            foreach ($sql->fetchAll(\PDO::FETCH_CLASS, __CLASS__) as $sponsor) {
                // imagen
                if (!empty($sponsor->image)) {
                    $sponsor->image = Image::get($sponsor->image);
                }

                $list[] = $sponsor;
            }

            return $list;
        }

        public function validate (&$errors = array()) {
            if (empty($this->call))
                $errors[] = 'Falta convocatoria';
/*
            if (empty($this->name))
                $errors[] = 'Falta nombre';

            if (empty($this->url))
                $errors[] = 'Falta enlace';
*/
            if (empty($errors))
                return true;
            else
                return false;
        }

        public function save (&$errors = array()) {
            if (!$this->validate($errors)) return false;

            // Primero la imagenImagen
            if (is_array($this->image) && !empty($this->image['name'])) {
                $image = new Image($this->image);
                // eliminando tabla images
                $image->newstyle = true; // comenzamosa  guardar nombre de archivo en la tabla

                if ($image->save($errors)) {
                    $this->image = $image->id;
                } else {
                    \Goteo\Library\Message::Error(Text::get('image-upload-fail') . implode(', ', $errors));
                    $this->image = '';
                }
            } elseif ($this->image instanceof Image) {
                $this->image = $this->image->id;
            }

            $fields = array(
                'id',
                'call',
                'name',
                'url',
                'image',
                'order'
                );

            $set = '';
            $values = array();

            foreach ($fields as $field) {
                if ($set != '') $set .= ", ";
                $set .= "`$field` = :$field ";
                $values[":$field"] = $this->$field;
            }

            try {
                $sql = "REPLACE INTO call_sponsor SET " . $set;
                self::query($sql, $values);
                if (empty($this->id)) $this->id = self::insertId();

                return true;
            } catch(\PDOException $e) {
                $errors[] = "HA FALLADO!!! " . $e->getMessage();
                return false;
            }
        }

        /**
         * Static compatible version of parent delete()
         * @param  [type] $id [description]
         * @return [type]     [description]
         */
        public function delete($id = null) {
            if(empty($id)) return parent::delete();

            if(!($ob = Sponsor::get($id))) return false;
            return $ob->delete();

        }

        /*
         * Para que salga antes  (disminuir el order)
         */
        public static function up ($id, $call) {
            $extra = array (
                    'call' => $call
                );
            return Check::reorder($id, 'up', 'call_sponsor', 'id', 'order', $extra);
        }

        /*
         * Para que salga despues  (aumentar el order)
         */
        public static function down ($id, $call) {
            $extra = array (
                    'call' => $call
                );
            return Check::reorder($id, 'down', 'call_sponsor', 'id', 'order', $extra);
        }

        /*
         * Orden para añadirlo al final
         */
        public static function next ($call) {
            $sql = self::query('SELECT MAX(`order`) FROM call_sponsor WHERE `call` = :call', array(':call' => $call));
            $order = $sql->fetchColumn(0);
            return ++$order;

        }

    }

}