/**
 * Forum interaction sociogram using SVG with a force-directed circular layout.
 *
 * Visualises the social network of student forum interactions as a directed
 * graph. Nodes represent students; edges represent reply relationships.
 * No external library dependency — renders using inline SVG with a simple
 * force-directed simulation.
 *
 * Based on the network analysis approach described by Macfadyen & Dawson (2010)
 * using SNAPP-style forum sociograms.
 *
 * @module     gradereport_coifish/sociogram
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('gradereport_coifish/sociogram', [], function() {

    /** SVG namespace. */
    var SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * Create an SVG element with attributes.
     *
     * @param {string} tag SVG element tag name.
     * @param {Object} attrs Attribute key-value pairs.
     * @returns {SVGElement} The created element.
     */
    function svgEl(tag, attrs) {
        var el = document.createElementNS(SVG_NS, tag);
        for (var k in attrs) {
            if (attrs.hasOwnProperty(k)) {
                el.setAttribute(k, attrs[k]);
            }
        }
        return el;
    }

    /**
     * Get risk colour for a node based on grade.
     *
     * @param {number|null} grade Student grade percentage.
     * @param {number} passThreshold Pass mark threshold.
     * @returns {string} Hex colour.
     */
    function nodeColour(grade, passThreshold) {
        if (grade === null || grade === undefined) {
            return '#adb5bd';
        }
        if (grade >= 75) {
            return '#28a745';
        }
        if (grade >= passThreshold) {
            return '#ffc107';
        }
        return '#dc3545';
    }

    /**
     * Run a simple force-directed layout simulation.
     *
     * @param {Array} nodes Array of node objects with x, y properties.
     * @param {Array} edges Array of edge objects with source, target indices.
     * @param {number} width Canvas width.
     * @param {number} height Canvas height.
     * @param {number} iterations Number of simulation steps.
     */
    function forceLayout(nodes, edges, width, height, iterations) {
        var i, j, n, e, dx, dy, dist, force, repulsion, attraction, damping;
        n = nodes.length;
        repulsion = 8000;
        attraction = 0.005;
        damping = 0.9;

        // Initialise positions in a circle.
        for (i = 0; i < n; i++) {
            var angle = (2 * Math.PI * i) / n;
            nodes[i].x = width / 2 + (width * 0.35) * Math.cos(angle);
            nodes[i].y = height / 2 + (height * 0.35) * Math.sin(angle);
            nodes[i].vx = 0;
            nodes[i].vy = 0;
        }

        for (var iter = 0; iter < iterations; iter++) {
            // Repulsion between all node pairs.
            for (i = 0; i < n; i++) {
                for (j = i + 1; j < n; j++) {
                    dx = nodes[i].x - nodes[j].x;
                    dy = nodes[i].y - nodes[j].y;
                    dist = Math.sqrt(dx * dx + dy * dy) || 1;
                    force = repulsion / (dist * dist);
                    var fx = (dx / dist) * force;
                    var fy = (dy / dist) * force;
                    nodes[i].vx += fx;
                    nodes[i].vy += fy;
                    nodes[j].vx -= fx;
                    nodes[j].vy -= fy;
                }
            }

            // Attraction along edges.
            for (e = 0; e < edges.length; e++) {
                var s = edges[e].sourceIdx;
                var t = edges[e].targetIdx;
                dx = nodes[t].x - nodes[s].x;
                dy = nodes[t].y - nodes[s].y;
                dist = Math.sqrt(dx * dx + dy * dy) || 1;
                force = attraction * dist;
                var afx = (dx / dist) * force;
                var afy = (dy / dist) * force;
                nodes[s].vx += afx;
                nodes[s].vy += afy;
                nodes[t].vx -= afx;
                nodes[t].vy -= afy;
            }

            // Centre gravity.
            for (i = 0; i < n; i++) {
                nodes[i].vx += (width / 2 - nodes[i].x) * 0.001;
                nodes[i].vy += (height / 2 - nodes[i].y) * 0.001;
            }

            // Apply velocity with damping.
            for (i = 0; i < n; i++) {
                nodes[i].vx *= damping;
                nodes[i].vy *= damping;
                nodes[i].x += nodes[i].vx;
                nodes[i].y += nodes[i].vy;
                // Boundary clamping.
                var r = nodes[i].radius || 8;
                nodes[i].x = Math.max(r + 2, Math.min(width - r - 2, nodes[i].x));
                nodes[i].y = Math.max(r + 2, Math.min(height - r - 2, nodes[i].y));
            }
        }
    }

    /**
     * Build the sociogram tooltip element.
     *
     * @param {HTMLElement} container The parent container.
     * @returns {HTMLElement} Tooltip div.
     */
    function createTooltip(container) {
        var tip = document.createElement('div');
        tip.className = 'gradetracker-sociogram-tooltip';
        tip.style.display = 'none';
        container.appendChild(tip);
        return tip;
    }

    return {
        /**
         * Initialise the sociogram visualisation.
         *
         * @param {string} containerId The ID of the container element.
         */
        init: function(containerId) {
            var container = document.getElementById(containerId);
            if (!container) {
                return;
            }

            var nodesRaw, edgesRaw;
            try {
                nodesRaw = JSON.parse(container.getAttribute('data-nodes'));
                edgesRaw = JSON.parse(container.getAttribute('data-edges'));
            } catch (e) {
                return;
            }

            if (!nodesRaw || !nodesRaw.length) {
                return;
            }

            var passThreshold = parseFloat(container.getAttribute('data-pass-threshold')) || 50;
            var width = container.clientWidth || 600;
            var height = Math.min(width * 0.75, 450);

            // Build node index.
            var nodeIndex = {};
            var nodes = nodesRaw.map(function(n, i) {
                nodeIndex[n.id] = i;
                var radius = Math.max(6, Math.min(20, 4 + (n.posts || 0) * 1.5));
                return {
                    id: n.id,
                    label: n.label,
                    grade: n.grade,
                    posts: n.posts || 0,
                    radius: radius,
                    colour: nodeColour(n.grade, passThreshold),
                    x: 0,
                    y: 0,
                    vx: 0,
                    vy: 0
                };
            });

            // Build edges with resolved indices.
            var edges = [];
            (edgesRaw || []).forEach(function(e) {
                if (nodeIndex[e.from] !== undefined && nodeIndex[e.to] !== undefined) {
                    edges.push({
                        sourceIdx: nodeIndex[e.from],
                        targetIdx: nodeIndex[e.to],
                        weight: e.weight || 1
                    });
                }
            });

            // Run layout.
            forceLayout(nodes, edges, width, height, 200);

            // Create SVG.
            var svg = svgEl('svg', {
                width: width,
                height: height,
                viewBox: '0 0 ' + width + ' ' + height,
                'class': 'gradetracker-sociogram-svg'
            });

            // Arrow marker definition.
            var defs = svgEl('defs', {});
            var marker = svgEl('marker', {
                id: 'sociogram-arrow-' + containerId,
                viewBox: '0 0 10 10',
                refX: 10,
                refY: 5,
                markerWidth: 6,
                markerHeight: 6,
                orient: 'auto-start-reverse',
                fill: '#999'
            });
            marker.appendChild(svgEl('path', {d: 'M 0 0 L 10 5 L 0 10 z'}));
            defs.appendChild(marker);
            svg.appendChild(defs);

            // Draw edges.
            var maxWeight = 1;
            edges.forEach(function(e) {
                if (e.weight > maxWeight) {
                    maxWeight = e.weight;
                }
            });
            edges.forEach(function(e) {
                var s = nodes[e.sourceIdx];
                var t = nodes[e.targetIdx];
                // Shorten line to stop at node edge.
                var dx = t.x - s.x;
                var dy = t.y - s.y;
                var dist = Math.sqrt(dx * dx + dy * dy) || 1;
                var offsetX = (dx / dist) * t.radius;
                var offsetY = (dy / dist) * t.radius;
                var strokeWidth = Math.max(0.5, Math.min(3, (e.weight / maxWeight) * 3));
                svg.appendChild(svgEl('line', {
                    x1: s.x,
                    y1: s.y,
                    x2: t.x - offsetX,
                    y2: t.y - offsetY,
                    stroke: '#bbb',
                    'stroke-width': strokeWidth,
                    'stroke-opacity': '0.6',
                    'marker-end': 'url(#sociogram-arrow-' + containerId + ')'
                }));
            });

            // Draw nodes.
            var tooltip = createTooltip(container);
            nodes.forEach(function(node) {
                var g = svgEl('g', {'class': 'gradetracker-sociogram-node', 'data-userid': node.id});
                g.appendChild(svgEl('circle', {
                    cx: node.x,
                    cy: node.y,
                    r: node.radius,
                    fill: node.colour,
                    stroke: '#fff',
                    'stroke-width': 2
                }));
                // Label for larger nodes.
                if (node.radius >= 10) {
                    var text = svgEl('text', {
                        x: node.x,
                        y: node.y + node.radius + 14,
                        'text-anchor': 'middle',
                        'class': 'gradetracker-sociogram-label'
                    });
                    text.textContent = node.label.split(' ')[0]; // First name only.
                    g.appendChild(text);
                }
                // Hover tooltip.
                g.addEventListener('mouseenter', function(ev) {
                    var gradeStr = node.grade !== null && node.grade !== undefined ? node.grade + '%' : '–';
                    tooltip.innerHTML = '<strong>' + node.label + '</strong><br>' +
                        'Grade: ' + gradeStr + '<br>' +
                        'Posts: ' + node.posts;
                    tooltip.style.display = 'block';
                    tooltip.style.left = ev.offsetX + 12 + 'px';
                    tooltip.style.top = ev.offsetY - 10 + 'px';
                });
                g.addEventListener('mouseleave', function() {
                    tooltip.style.display = 'none';
                });
                svg.appendChild(g);
            });

            container.appendChild(svg);
        }
    };
});
