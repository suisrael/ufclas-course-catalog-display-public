<?php
/*
Plugin Name: UFCLAS Course Catalog Display
Description: A plugin to dynamically fetch and display course data from an XML file.
Version: 1.4
Author: Sanjana Ramadugu

Instructions:
   Add the shortcode below to any page or post to display the course catalog:
   [display_courses url="[page url]/index.xml" tabs="tab1,tab2" remove_paragraph="para1,para2"]

    To display only specific sections, specify the `tabs` attribute with an array of the tabs needed.
    Separate multiple tabs with commas. If no `tabs` are specified, all the sections in the XML will be displayed.

    Some example mappings for the tabs are:
        text -> Overview
        criticaltrackingtext -> Critical Tracking
        modelsemesterplantext -> Model Semester Plan
        concentrationcoursestext -> Concentration Courses

    To remove one or more introductory paragraphs from the Overview section, add the `remove_paragraph` attribute.
    Paragraphs are counted from the top and removed before rendering.

    Example:
        [display_courses url="[page url]/index.xml" tabs="text,criticaltrackingtext" remove_paragraph="1,2"]
        
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function fetch_and_parse_xml($atts) {
    $atts = shortcode_atts(
        [        
            'url' => '',
            'tabs' => '',
            'remove_paragraph' => ''
        ],
        $atts,
        'display_courses'
    );

    $xml_url = esc_url($atts['url']);
    $selected_tabs = array_map('trim', explode(',', strtolower($atts['tabs'])));
    $remove_paragraphs = array_filter(array_map('intval', explode(',', $atts['remove_paragraph'])));

    if (empty($xml_url)) {
        return '<p style="color:red;">No XML URL provided.</p>';
    }

    try {
        $xml = simplexml_load_file($xml_url);

        if (!$xml) {
            return '<p style="color:red;">Failed to load the XML file. Please check the URL.</p>';
        }

        $output = '<div class="course-catalog">';

        // Add navigation JavaScript
        $output .= '<script>
        function showSection(sectionId, element) {
            // Find the target section
            var targetSection = document.querySelector("[data-section-id=\"" + sectionId + "\"]");
            if (targetSection) {
                targetSection.scrollIntoView({ behavior: "smooth" });
            }
            
            return false;
        }
        </script>';

        // Dynamically detect available sections
        $available_sections = [];
        foreach ($xml->children() as $node) {
            $node_name = strtolower($node->getName());

            if ($node_name === "title") {
                continue; // Skip title section
            }

            // If it's "text", default to "Overview"
            if ($node_name === "text") {
                $tab_label = "Overview";
            } else {
                // For everything else, use the name attribute if available, otherwise format the node name
                $tab_label = isset($node['name']) ? (string) $node['name'] : ucfirst(str_replace('_', ' ', $node_name));
            }

            $available_sections[$node_name] = [
                'xml_node' => $node_name,
                'label' => $tab_label,
            ];
        }

        // If no tabs are specified, show all sections
        if (empty($selected_tabs) || (count($selected_tabs) === 1 && $selected_tabs[0] === '')) {
            $selected_tabs = array_keys($available_sections);
        }

        foreach ($selected_tabs as $tab) {
            if (isset($available_sections[$tab])) {
                $section_info = $available_sections[$tab];
                $section_name = $section_info['xml_node'];
                $section_label = $section_info['label'];

                $output .= '<h2>' . esc_html($section_label) . '</h2>';

                $section_html = (string)$xml->$section_name;

                // Fix navigation issues and convert relative URLs
                $section_html = fix_navigation_html($section_html, $section_name);
                $section_html = fix_relative_urls($section_html, $xml_url);
                $section_html = add_css_classes_to_lists($section_html, $section_name);
                $section_html = fix_navigation_text($section_html);
                $section_html = open_links_in_new_tab($section_html);

                if ($section_name === 'text' && !empty($remove_paragraphs)) {
                    $doc = new DOMDocument();
                    libxml_use_internal_errors(true);
                    $doc->loadHTML(mb_convert_encoding($section_html, 'HTML-ENTITIES', 'UTF-8'));
                    libxml_clear_errors();

                    $xpath = new DOMXPath($doc);
                    $p_tags = $xpath->query('//p');

                    foreach ($remove_paragraphs as $index_to_remove) {
                        $index = $index_to_remove - 1;
                        if (isset($p_tags[$index]) && $p_tags[$index]->parentNode) {
                            $p_tags[$index]->parentNode->removeChild($p_tags[$index]);
                        }
                    }

                    $body = $doc->getElementsByTagName('body')->item(0);
                    $section_html = '';
                    foreach ($body->childNodes as $child) {
                        $section_html .= $doc->saveHTML($child);
                    }
                }

                $output .= '<div class="course-section">' . $section_html . '</div>';

            }
        }

        $output .= '</div>';

        return $output;

    } catch (Exception $e) {
        return '<p style="color:red;">Error parsing the XML: ' . $e->getMessage() . '</p>';
    }
}

function open_links_in_new_tab($html) {
    // Load HTML into DOMDocument for manipulation
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    
    // Find all links within list items (program/course links)
    $program_links = $xpath->query('//li//a[@href]');
    
    foreach ($program_links as $link) {
        $href = $link->getAttribute('href');
        
        // Skip internal navigation links (those with # or onclick for internal navigation)
        $onclick = $link->getAttribute('onclick');
        if (strpos($href, '#') === 0 || !empty($onclick)) {
            continue; // Skip internal navigation links
        }
        
        // Skip if it's already set to open in new tab
        if ($link->getAttribute('target') === '_blank') {
            continue;
        }
        
        // Add target="_blank" and rel="noopener" for security
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
    }
    
    // Also handle any other external links that aren't navigation
    $external_links = $xpath->query('//a[@href and not(starts-with(@href, "#")) and not(@onclick)]');
    
    foreach ($external_links as $link) {
        $href = $link->getAttribute('href');
        
        // Skip if it's already set to open in new tab
        if ($link->getAttribute('target') === '_blank') {
            continue;
        }
        
        // Check if it's an external link (starts with http/https or is an absolute path)
        if (preg_match('/^https?:\/\//', $href) || strpos($href, '/') === 0) {
            // Skip if it's within navigation elements
            $parent = $link->parentNode;
            $is_navigation = false;
            
            while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
                $classes = $parent->getAttribute('class');
                if (strpos($classes, 'otp') !== false || 
                    strpos($classes, 'notinpdf') !== false ||
                    strpos($classes, 'onthispage') !== false) {
                    $is_navigation = true;
                    break;
                }
                $parent = $parent->parentNode;
            }
            
            // Only add target="_blank" to non-navigation links
            if (!$is_navigation) {
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }
    
    return $doc->saveHTML();
}

function fix_navigation_text($html) {
    // Load HTML into DOMDocument for manipulation
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    
    // Find elements with class "otp-title" that contain "On This Tab"
    $otp_titles = $xpath->query('//div[contains(@class, "otp-title")]');
    
    foreach ($otp_titles as $title) {
        $text_content = $title->textContent;
        
        // Replace "On This Tab" with "On This Page" and convert to H2
        if (stripos($text_content, 'on this tab') !== false || stripos($text_content, 'this tab') !== false) {
            $new_text = str_ireplace(
                ['on this tab', 'this tab', 'in this tab'],
                ['On This Page', 'On This Page', 'On This Page'],
                $text_content
            );
            
            // Create new H2 element
            $h3 = $doc->createElement('h3', htmlspecialchars($new_text));
            
            // Copy any existing classes from the div to the h2
            $existing_class = $title->getAttribute('class');
            if (!empty($existing_class)) {
                $h3->setAttribute('class', $existing_class);
            }
            
            // Replace the div with the h2
            $title->parentNode->replaceChild($h3, $title);
        }
    }
    
    // Also check for any other elements that might contain "tab" references in navigation context
    $all_text_nodes = $xpath->query('//text()[contains(translate(., "TAB", "tab"), "tab")]');
    
    foreach ($all_text_nodes as $text_node) {
        $text_content = $text_node->textContent;
        
        // Only replace if it's in a navigation context (check parent elements)
        $parent = $text_node->parentNode;
        $is_navigation = false;
        
        // Check if it's within navigation-related elements (but not already processed otp-title)
        while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
            $classes = $parent->getAttribute('class');
            if ((strpos($classes, 'otp') !== false || 
                strpos($classes, 'nav') !== false || 
                strpos($classes, 'menu') !== false ||
                $parent->tagName === 'nav') &&
                strpos($classes, 'otp-title') === false) {
                $is_navigation = true;
                break;
            }
            $parent = $parent->parentNode;
        }
        
        if ($is_navigation) {
            // Replace tab references with page references
            $new_text = str_ireplace(
                ['on this tab', 'this tab', 'in this tab'],
                ['On This Page', 'This Page', 'On This Page'],
                $text_content
            );
            
            if ($new_text !== $text_content) {
                $text_node->textContent = $new_text;
            }
        }
    }
    
    return $doc->saveHTML();
}

function add_css_classes_to_lists($html, $section_name) {
    // Load HTML into DOMDocument for manipulation
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    
    // Find all divs that contain ul elements (more generic approach)
    $list_container_divs = $xpath->query('//div[ul]');
    
    foreach ($list_container_divs as $div) {
        $existing_class = $div->getAttribute('class');
        
        // Find the preceding h2 to determine which section this list belongs to
        $preceding_h2 = $xpath->query('preceding-sibling::h2[1] | preceding::h2[1]', $div);
        
        if ($preceding_h2->length > 0) {
            $h2_text = trim($preceding_h2->item(0)->textContent);
            
            // Create CSS class dynamically from the h2 text
            $section_class = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $h2_text)) . '-list';
            
            // Add generic list container class and section-specific class
            $new_class = trim($existing_class . ' course-list-container ' . $section_class . ' section-' . $section_name);
            $div->setAttribute('class', $new_class);
        } else {
            // Fallback if no preceding h2 found
            $new_class = trim($existing_class . ' course-list-container general-list section-' . $section_name);
            $div->setAttribute('class', $new_class);
        }
    }
    
    // Find all ul elements and add classes
    $ul_elements = $xpath->query('//ul');
    
    foreach ($ul_elements as $ul) {
        $existing_class = $ul->getAttribute('class');
        
        // Find the preceding h2 to determine context
        $preceding_h2 = $xpath->query('preceding-sibling::h2[1] | preceding::h2[1]', $ul);
        
        if ($preceding_h2->length > 0) {
            $h2_text = trim($preceding_h2->item(0)->textContent);
            $list_class = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $h2_text)) . '-ul';
            
            $new_class = trim($existing_class . ' course-list ' . $list_class);
            $ul->setAttribute('class', $new_class);
        } else {
            $new_class = trim($existing_class . ' course-list general-ul');
            $ul->setAttribute('class', $new_class);
        }
    }
    
    // Add classes to all list items that contain links
    $list_items_with_links = $xpath->query('//li[a]');
    
    foreach ($list_items_with_links as $li) {
        $existing_class = $li->getAttribute('class');
        
        // Find the link inside the li
        $link = $xpath->query('.//a', $li);
        if ($link->length > 0) {
            $link_href = $link->item(0)->getAttribute('href');
            $link_text = trim($link->item(0)->textContent);
            
            // Create a CSS class from the program/item name
            $item_class = 'item-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $link_text));
            
            // Analyze the URL to determine types (generic patterns)
            $type_classes = [];
            
            // Look for common degree patterns in URLs
            if (preg_match('/_([A-Z]{2,4})(?:_|\/|$)/', $link_href, $matches)) {
                $degree_type = strtolower($matches[1]);
                $type_classes[] = 'degree-' . $degree_type;
            }
            
            // Look for common academic program indicators
            if (preg_match('/minor|mn/i', $link_href)) {
                $type_classes[] = 'type-minor';
            }
            if (preg_match('/certificate|cert|ct/i', $link_href)) {
                $type_classes[] = 'type-certificate';
            }
            if (preg_match('/online|ufo|distance/i', $link_href)) {
                $type_classes[] = 'type-online';
            }
            if (preg_match('/graduate|grad|masters|phd|doctoral/i', $link_href)) {
                $type_classes[] = 'level-graduate';
            }
            if (preg_match('/undergraduate|undergrad|bachelor/i', $link_href)) {
                $type_classes[] = 'level-undergraduate';
            }
            
            // Extract department/college from URL structure
            if (preg_match('/\/([^\/]+)\/[^\/]*$/', $link_href, $matches)) {
                $dept = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $matches[1]));
                $type_classes[] = 'dept-' . $dept;
            }
            
            $new_class = trim($existing_class . ' course-item ' . $item_class . ' ' . implode(' ', $type_classes));
            $li->setAttribute('class', $new_class);
        }
    }
    
    return $doc->saveHTML();
}

function fix_relative_urls($html, $xml_url) {
    // Extract base URL from the XML URL
    $parsed_url = parse_url($xml_url);
    $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    
    // Load HTML into DOMDocument for manipulation
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    
    // Find all links that start with "/" (relative URLs)
    $relative_links = $xpath->query('//a[starts-with(@href, "/")]');
    
    foreach ($relative_links as $link) {
        $href = $link->getAttribute('href');
        $complete_url = $base_url . $href;
        $link->setAttribute('href', $complete_url);
    }
    
    // Find all images that start with "/" (relative URLs)
    $relative_images = $xpath->query('//span[starts-with(@style, "background-image: url(\'/")]');
    
    foreach ($relative_images as $span) {
        $style = $span->getAttribute('style');
        // Replace relative URLs in background-image style
        $updated_style = preg_replace(
            '/background-image:\s*url\([\'"]?(\/[^\'")]+)[\'"]?\)/',
            'background-image: url(\'' . $base_url . '$1\')',
            $style
        );
        $span->setAttribute('style', $updated_style);
    }
    
    // Also handle regular img tags if any
    $relative_img_tags = $xpath->query('//img[starts-with(@src, "/")]');
    
    foreach ($relative_img_tags as $img) {
        $src = $img->getAttribute('src');
        $complete_url = $base_url . $src;
        $img->setAttribute('src', $complete_url);
    }
    
    return $doc->saveHTML();
}

function fix_navigation_html($html, $section_name) {
    // Load HTML into DOMDocument for manipulation
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    
    // Find all h2 elements with headerid attribute
    $h2_elements = $xpath->query('//h2[@headerid]');
    
    foreach ($h2_elements as $h2) {
        $header_id = $h2->getAttribute('headerid');
        $unique_id = $section_name . '_section_' . $header_id;
        
        // Update the h2 element
        $h2->setAttribute('id', $unique_id);
        $h2->setAttribute('data-section-id', $unique_id);
        
        // Find the parent container and add data attribute
        $parent = $h2->parentNode;
        while ($parent && $parent->nodeName !== 'div') {
            $parent = $parent->parentNode;
        }
        if ($parent) {
            $parent->setAttribute('data-section-id', $unique_id);
        }
    }
    
    // Update navigation links
    $nav_links = $xpath->query('//a[@nav-id]');
    foreach ($nav_links as $link) {
        $nav_id = $link->getAttribute('nav-id');
        $unique_id = $section_name . '_section_' . $nav_id;
        
        // Update href and onclick
        $link->setAttribute('href', '#' . $unique_id);
        $link->setAttribute('onclick', "showSection('" . $unique_id . "', this); return false;");
    }
    
    return $doc->saveHTML();
}

function register_course_catalog_shortcode() {
    add_shortcode('display_courses', 'fetch_and_parse_xml');
}
add_action('init', 'register_course_catalog_shortcode');

function course_catalog_styles() {
    wp_enqueue_style('course-catalog-css', plugins_url('style.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'course_catalog_styles');