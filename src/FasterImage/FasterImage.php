<?php namespace FasterImage;

use FasterImage\Exception\StreamBufferTooSmallException;

/**
 * FasterImage - Because sometimes you just want the size, and you want them in parallel!
 *
 * Based on the PHP stream implementation by Tom Moor (http://tommoor.com)
 * which was based on the original Ruby Implementation by Steven Sykes (https://github.com/sdsykes/fastimage)
 *
 * MIT Licensed
 * @version 0.01
 */
class FasterImage
{
    /**
     * Get the size of each of the urls in a list
     *
     * @param array $urls
     *
     * @return array
     * @throws \Exception
     */
    public function batch(array $urls) {

        $multi = curl_multi_init();
        $results = array();

        foreach ( $urls as $uri ) {

            $results[$uri] =[];

            $code = curl_multi_add_handle($multi, $this->handle($uri, $results[$uri]));

            if ( $code != CURLM_OK ) {
                throw new \Exception("Curl handle for $uri could not be added");
            }
        }

        do {
            while ( ($mrc = curl_multi_exec($multi, $active)) == CURLM_CALL_MULTI_PERFORM ) ;
            if ( $mrc != CURLM_OK && $mrc != CURLM_CALL_MULTI_PERFORM ) {
                throw new \Exception("Curl error code: $mrc");
            }

            if ( $active && curl_multi_select($multi) === -1 ) {
                // Perform a usleep if a select returns -1.
                // See: https://bugs.php.net/bug.php?id=61141
                usleep(250);
            }
        } while ( $active );

        return $results;

    }


    /**
     * Create the handle for the curl request
     *
     * @param $url
     * @param $result
     *
     * @return resource
     */
    private function handle($url, & $result)
    {
        $stream           = new StreamParser();
        $result['rounds'] = 0;
        $result['bytes']  = 0;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 256);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5",
            "Cache-Control: max-age=0",
            "Connection: keep-alive",
            "Keep-Alive: 300",
            "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7",
            "Accept-Language: en-us,en;q=0.5",
            "Pragma: ", // browsers keep this blank.
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        #  Some web servers require the useragent to be not a bot. So we are liars.
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.110 Safari/537.36');
        # Honestly don't remember why we do this
        curl_setopt($ch, CURLOPT_ENCODING, "");

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $str) use (& $result, & $stream) {

            $result['rounds']++;
            $result['bytes'] += strlen($str);

            $stream->append($str);

            try {
                // store the type in the result array by looking at the bits
                $result['type'] = $stream->parseType();

                /*
                 * We try here to parse the buffer of characters we already have
                 * for the size.
                 */
                $result['size'] = $stream->parseSize();
            }
            catch (StreamBufferTooSmallException $e) {
                /*
                 * If this exception is thrown, we don't have enough of the stream buffered
                 * so in order to tell curl to keep streaming we need to return the number
                 * of bytes we have already handled
                 *
                 * We set the 'size' to 'failed' in the case that we've done
                 * the entire image and we couldn't figure it out. Otherwise
                 * it'll get overwritten with the next round.
                 */
                $result['size'] = 'failed';

                return strlen($str);
            }

            /*
             * We return -1 to abort the transfer when we have enough buffered
             * to find the size
             */
            //
            // hey curl! this is an error. But really we just are stopping cause
            // we already have what we wwant
            return -1;
        });

        return $ch;
    }
}
