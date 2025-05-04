jQuery(document).ready(function($) {
    // Initialize the dashboard
    loadProductsData();
    setupEventListeners();

    function loadProductsData() {
        console.log('Loading products data...');
        $.ajax({
            url: hempiesCoaData.ajaxurl,
            type: 'POST',
            data: {
                action: 'hempies_coa_get_products',
                nonce: hempiesCoaData.nonce
            },
            success: function(response) {
                console.log('Response received:', response);
                if (response.success && response.data.products) {
                    populateProductsTable(response.data.products);
                } else {
                    console.error('Failed to load products data:', response.data);
                    alert('Failed to load products data. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading products data:', error);
                alert('Error loading products data. Please check the console for details.');
            }
        });
    }

    function populateProductsTable(products) {
        console.log('Populating products table with:', products);
        const tbody = $('#coa-products tbody');
        tbody.empty();

        if (!products || products.length === 0) {
            tbody.append('<tr><td colspan="4" class="no-products">No products found</td></tr>');
            return;
        }

        products.forEach(function(product) {
            const row = $('<tr>');
            row.append($('<td>').text(product.sku));
            row.append($('<td>').text(product.name));
            row.append($('<td>').html(getStatusBadge(product.status)));
            row.append($('<td>').text(product.creation_date));
            row.append($('<td>').html(getActionButtons(product)));
            tbody.append(row);
        });
    }

    function getStatusBadge(status) {
        const statusText = status ? status.replace('_', ' ').toUpperCase() : 'NEEDS COA';
        const statusClass = status ? `status-${status}` : 'status-needs_coa';
        return `<span class="status-badge ${statusClass}">${statusText}</span>`;
    }

    function getActionButtons(product) {
        let buttons = '<div class="action-buttons">';
        
        if (product.actions.edit) {
            buttons += `<button class="edit-btn" onclick="window.location.href='${product.actions.edit}'">Edit</button>`;
        }
        
        if (product.actions.view && product.actions.view !== '#') {
            buttons += `<button class="view-btn" onclick="window.open('${product.actions.view}', '_blank')">View COA</button>`;
        }
        
        buttons += '</div>';
        return buttons;
    }

    function setupEventListeners() {
        // Search functionality
        $('#coa-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('#coa-products tbody tr').each(function() {
                const rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.includes(searchTerm));
            });
        });

        // Status filter
        $('#coa-status-filter').on('change', function() {
            const selectedStatus = $(this).val();
            $('#coa-products tbody tr').each(function() {
                if (!selectedStatus) {
                    $(this).show();
                    return;
                }
                const statusBadge = $(this).find('.status-badge');
                const rowStatus = statusBadge.attr('class').match(/status-(\w+)/)[1];
                $(this).toggle(rowStatus === selectedStatus);
            });
        });
    }
}); 