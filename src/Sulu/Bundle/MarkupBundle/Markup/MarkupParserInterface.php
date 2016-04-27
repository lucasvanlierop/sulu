<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\MarkupBundle\Markup;

/**
 * Parses html content and replaces special tags.
 */
interface MarkupParserInterface
{
    /**
     * Parses html-document and returns completed html.
     *
     * @param string $html
     *
     * @return string
     */
    public function parse($html);
}