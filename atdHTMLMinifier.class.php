<?php
/*
 * HTML OPTIMIERUNG PROOF OF CONCEPT nach
 * http://perfectionkills.com/optimizing-html/
 * http://code.google.com/intl/de-DE/speed/articles/optimizing-html.html
 *
 */
class atdHTMLMinifier
{

  static protected function domNodeDiscardable(DOMNode $node)
  {
    return ($node->tagName == 'meta' && strtolower($node->getAttribute('http-equiv')) == 'content-type');
  }

  static protected function getNextSiblingOfTypeDOMElement(DOMNode $node)
  {
    do
    {
      $node = $node->nextSibling;
    } 
    while (!($node === null || $node instanceof DOMElement));
    return $node;  
  }

  static protected function domAttrDiscardable(DOMAttr $attr)
  {
    #(!in_array($attr->ownerElement->tagName, array('input', 'select', 'button', 'textarea')) && $attr->name == 'name' && $attr->ownerElement->getAttribute('id') == $attr->value) # elements with pairing name/id’s
    return ($attr->ownerElement->tagName == 'form' && $attr->name == 'method' && strtolower($attr->value) == 'get') # <form method="get"> is default
        || ($attr->ownerElement->tagName == 'style' && $attr->name == 'media' && strtolower($attr->value) == 'all') # <style media="all"> is implicit default
        || ($attr->ownerElement->tagName == 'input' && $attr->name == 'type' && strtolower($attr->value) == 'text') # <input type="text"> is default
         ;
  }

  #http://www.whatwg.org/specs/web-apps/current-work/multipage/syntax.html#void-elements
  private static $void_elements = array('area', 'base', 'br', 'col', 'command', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr');
  private static $optional_end_tags = array('html', 'head', 'body');

  static protected function domNodeClosingTagOmittable(DOMNode $node)
  {
    # TODO: Exakt die Spezifikation implementieren, indem nachfolgende Elemente
    # mitbetrachtet werden
    # http://www.whatwg.org/specs/web-apps/current-work/multipage/syntax.html#optional-tags

    $tag_name = $node->tagName;
    #$nextSibling = self::getNextSiblingOfTypeDOMElement($node);
    $nextSibling = $node->nextSibling;
    return in_array($tag_name, self::$void_elements)
        || in_array($tag_name, self::$optional_end_tags)
        || ($tag_name == 'li' && ($nextSibling === null || ($nextSibling instanceof DOMElement && $nextSibling->tagName == $tag_name)))
        || ($tag_name == 'p' && (($nextSibling === null && ($node->parentNode !== null && $node->parentNode->tagName != 'a'))
                                 || ($nextSibling instanceof DOMElement && in_array($nextSibling->tagName, array('address', 'article', 'aside', 'blockquote', 'dir', 
                                                                          'div', 'dl', 'fieldset', 'footer', 'form', 'h1', 'h2', 
                                                                          'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'menu', 
                                                                          'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul')))
                                )
           );

  }

  static protected function domAttrIsBoolean(DOMAttr $attr)
  {
    # INKOMPLETT !!
    # gibt anscheinend keine Liste
    # http://www.google.de/#hl=de&source=hp&q=%22boolean+attribute%22+site%3Ahttp%3A%2F%2Fwww.whatwg.org%2Fspecs%2Fweb-apps%2Fcurrent-work%2Fmultipage%2F&aq=f&aqi=&aql=&oq=&gs_rfai=&fp=e48ec3a97faa7ccb
    #
    $tag_name = $attr->ownerElement->tagName;
    return $attr->name == 'hidden'
        || ($tag_name == 'fieldset' && in_array($attr->name, array('disabled', 'readonly')))
        || ($tag_name == 'option' && in_array($attr->name, array('disabled', 'readonly', 'selected')))
        || ($tag_name == 'input' && in_array($attr->name, array('disabled', 'readonly', 'checked', 'required')))
        || ($tag_name == 'select' && in_array($attr->name, array('disabled', 'readonly', 'multiple', 'required')))
        ;
    
  }

  static protected function domNodeAttributesToString(DOMNode $node)
  {
    
    #Remove quotes around attribute values, when allowed (<p class="foo"> → <p class=foo>)
    #Remove optional values from boolean attributes (<option selected="selected"> → <option selected>)
    $attrstr = '';
    if ($node->attributes != null)
    {
      foreach ($node->attributes as $attribute)
      {
        if (self::domAttrDiscardable($attribute))
          continue;
        $attrstr .= $attribute->name;
        if (!self::domAttrIsBoolean($attribute))
        {
          $attrstr .= '=';

          # http://www.whatwg.org/specs/web-apps/current-work/multipage/syntax.html#attributes-0          
          $omitquotes = $attribute->value != '' && 0 == preg_match('/["\'=<>` \t\r\n\f]+/', $attribute->value) ;
          # DOM behält ENTITES nicht bei
          # http://www.w3.org/TR/2004/REC-xml-20040204/#sec-predefined-ent
          $attr_val = strtr($attribute->value, array('"' => '&quot;', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;'));
          $attrstr .= ($omitquotes ? '' : '"') . $attr_val . ($omitquotes ? '' : '"');
        }
        $attrstr .= ' ';
      }
    }
    return trim($attrstr);
  }

  static protected function domNodeToString(DOMNode $node)
  {
    $htmlstr = '';
    foreach ($node->childNodes as $child)
    {
      if ($child instanceof DOMDocumentType)
      {
        $htmlstr .= '<!doctype html>';
      } 
      else if ($child instanceof DOMElement)
      {
        if (!self::domNodeDiscardable($child))
        {
          $htmlstr .= trim('<' . $child->tagName . ' ' . self::domNodeAttributesToString($child));

          $htmlstr .= '>' . self::domNodeToString($child);
          if (!self::domNodeClosingTagOmittable($child))
          {
            $htmlstr .= '</' . $child->tagName . '>';
          }
        }
      } 
      else if ($child instanceof DOMText)
      {
        if ($child->isWhitespaceInElementContent())
        {
          if ($child->previousSibling !== null && $child->nextSibling !== null)
          {
            $htmlstr .= ' ';
          }
        } else
        {
          # DOM behält ENTITES nicht bei
          # http://www.w3.org/TR/2004/REC-xml-20040204/#sec-predefined-ent
          $htmlstr .= strtr($child->wholeText, array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;'));
        }
      }
      else if ($child instanceof DOMComment)
      {
        // KOMMENTARE SCHÖN IGNOREN
        // TODO KEEP IE CONDITIONAL COMMENTS
      }
      else
      {
        echo 'Unhandled:' . get_class($child) . "\n";
      }
    }
    return $htmlstr;
  }

  static function minify($html, $consider_inline = 'li')
  {
    $dom = new DOMDocument();
    $dom->substituteEntities = false;
    $dom->loadHTML(str_replace('<head>', '<head><Meta http-equiv="content-type" content="text/html; charset=utf-8">', $html));
    
    return self::domNodeToString($dom);
  }

}
