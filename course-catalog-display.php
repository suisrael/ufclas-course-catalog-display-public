<?php
/*
Plugin Name: UFCLAS Course Catalog Display
Description: A plugin to dynamically fetch and display course data from an XML file.
Version: 1.3
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

function register_course_catalog_shortcode() {
    add_shortcode('display_courses', 'fetch_and_parse_xml');
}
add_action('init', 'register_course_catalog_shortcode');

function course_catalog_styles() {
    wp_enqueue_style('course-catalog-css', plugins_url('style.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'course_catalog_styles');
