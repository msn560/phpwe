<?php
    function debug_print_style(){
        echo '<style>
            .db-debug-container {
                background-color: #1e1e2e;
                color: #fff;
                padding: 20px;
                margin: 20px;
                border-radius: 8px;
                font-family: "Cascadia Code", Consolas, Monaco, "Courier New", monospace;
                box-shadow: 0 4px 6px rgba(0,0,0,0.3);
                max-width: 100%;
                overflow-x: hidden;
            }
            .db-debug-container h2 {
                color: #89b4fa;
                border-bottom: 1px solid #45475a;
                padding-bottom: 10px;
                margin-top: 5px;
            }
            .db-debug-container h3 {
                color: #89b4fa;
                margin-top: 0;
            }
            .db-debug-container h4 {
                color: #74c7ec;
            }
            .db-debug-section {
                background-color: #181825;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .db-debug-section.connection {
                border-left: 4px solid #89b4fa;
            }
            .db-debug-section.cache {
                border-left: 4px solid #a6e3a1;
            }
            .db-debug-section.errors {
                border-left: 4px solid #f38ba8;
            }
            .db-debug-section.success {
                border-left: 4px solid #a6e3a1;
            }
            .db-debug-section.queries {
                border-left: 4px solid #fab387;
            }
            .db-debug-section.cache-stats {
                border-left: 4px solid #f9e2af;
            }
            .db-debug-list {
                list-style-type: none;
                padding-left: 10px;
            }
            .db-debug-list li {
                padding: 8px 0;
                border-bottom: 1px dashed #45475a;
            }
            .db-debug-list li:last-child {
                border-bottom: none;
            }
            .db-debug-item {
                padding: 8px;
                margin: 8px 0;
                background-color: #313244;
                border-radius: 4px;
            }
            .db-debug-table-container {
                overflow-x: auto;
                margin: 10px 0;
                border-radius: 4px;
            }
            .db-debug-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                min-width: 800px;
            }
            .db-debug-table th {
                background-color: #313244;
                color: #cdd6f4;
                padding: 10px;
                border-bottom: 2px solid #45475a;
            }
            .db-debug-table td {
                padding: 10px;
                border-bottom: 1px solid #45475a;
            }
            .db-debug-table tr:hover td {
                background-color: #313244;
            }
            .db-debug-status-connected {
                color: #a6e3a1;
                font-weight: bold;
            }
            .db-debug-status-disconnected {
                color: #f38ba8;
                font-weight: bold;
            }
            .db-debug-pre {
                background-color: #313244;
                color: #cdd6f4;
                padding: 10px;
                border-radius: 4px;
                overflow-x: auto;
                font-size: 0.9em;
                margin: 5px 0;
            }
            .db-debug-collapse-btn {
                background-color: #45475a;
                color: #cdd6f4;
                border: none;
                border-radius: 4px;
                padding: 5px 10px;
                font-size: 0.8em;
                cursor: pointer;
                margin-bottom: 10px;
                transition: background-color 0.2s;
            }
            .db-debug-collapse-btn:hover {
                background-color: #585b70;
            }
            .db-debug-badge {
                display: inline-block;
                padding: 3px 6px;
                border-radius: 3px;
                font-size: 0.75em;
                font-weight: bold;
                margin-left: 5px;
            }
            .db-debug-badge-error {
                background-color: #f38ba8;
                color: #11111b;
            }
            .db-debug-badge-success {
                background-color: #a6e3a1;
                color: #11111b;
            }
            .db-debug-badge-cache {
                background-color: #74c7ec;
                color: #11111b;
            }
            .db-debug-badge-query {
                background-color: #fab387;
                color: #11111b;
            }
            @media (max-width: 768px) {
                .db-debug-container {
                    padding: 10px;
                    margin: 10px;
                }
                .db-debug-section {
                    padding: 10px;
                }
            }
        </style>';

    }