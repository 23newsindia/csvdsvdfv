document.addEventListener('DOMContentLoaded', function() {
    //////// Toggle between table and editor views
    const addNewBtn = document.getElementById('cg-add-new');
    const gridList = document.querySelector('.cg-grid-list');
    const gridEditor = document.querySelector('.cg-grid-editor');

    if (addNewBtn && gridList && gridEditor) {
        addNewBtn.addEventListener('click', function() {
            gridList.style.display = 'none';
            gridEditor.style.display = 'block';
            resetEditor();
        });

        document.getElementById('cg-cancel-edit').addEventListener('click', function() {
            gridList.style.display = 'block';
            gridEditor.style.display = 'none';
        });
    }

    function resetEditor() {
        document.getElementById('cg-grid-name').value = '';
        document.getElementById('cg-grid-slug').value = '';
        document.getElementById('cg-selected-categories').innerHTML = '';
        document.querySelector('.cg-grid-editor').dataset.id = '';
        
        // Reset settings to defaults
        document.getElementById('cg-desktop-columns').value = '4';
        document.getElementById('cg-mobile-columns').value = '2';
        document.getElementById('cg-carousel-mobile').checked = true;
        document.getElementById('cg-image-size').value = 'medium';
    }

    // Handle Edit Grid
    document.addEventListener('click', async function(e) {
console.log('Click event target:', e.target);
      
    const editBtn = e.target.closest('.cg-edit-grid');
    if (editBtn) {
        const gridId = editBtn.dataset.id;

       // Add this second debug line to verify the grid ID
        console.log('Edit button clicked', e.target);
        console.log('Grid ID:', gridId);
           
            try {
                const formData = new FormData();
                formData.append('action', 'cg_get_grid');
                formData.append('id', gridId);
                formData.append('nonce', cg_admin_vars.nonce);

                const response = await fetch(cg_admin_vars.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.data || 'Failed to load grid');
                }

                // Populate editor with grid data
                const grid = data.data;
                document.getElementById('cg-grid-name').value = grid.name;
                document.getElementById('cg-grid-slug').value = grid.slug;
                document.querySelector('.cg-grid-editor').dataset.id = grid.id;

                // Load settings
                document.getElementById('cg-desktop-columns').value = grid.settings.desktop_columns;
                document.getElementById('cg-mobile-columns').value = grid.settings.mobile_columns;
                document.getElementById('cg-carousel-mobile').checked = grid.settings.carousel_mobile;
                document.getElementById('cg-image-size').value = grid.settings.image_size;

                // Load categories
                const selectedCategoriesContainer = document.getElementById('cg-selected-categories');
                selectedCategoriesContainer.innerHTML = '';
                
                grid.categories.forEach(category => {
                    const selectedItem = document.createElement('li');
                    selectedItem.dataset.id = category.id;
                    selectedItem.innerHTML = `
                        <div class="cg-category-meta">
                            <span class="cg-category-name">${category.name || ''}</span>
                            <button class="button cg-remove-category">Remove</button>
                        </div>
                        <div class="cg-category-fields">
                            <div class="cg-form-group">
                                <label>Custom Image URL</label>
                                <button class="button cg-upload-image">Upload</button>
                                <input type="text" class="cg-category-image regular-text" value="${category.image || ''}">
                                <div class="cg-image-preview" style="display: ${category.image ? 'block' : 'none'}">
                                    <img src="${category.image || ''}" style="max-width:100px;">
                                </div>
                            </div>
                            <div class="cg-form-group">
                                <label>Custom Link URL</label>
                                <input type="text" class="cg-category-link regular-text" 
                                       value="${category.link || ''}"
                                       placeholder="Leave blank for default category link">
                            </div>
                            <div class="cg-form-group">
                                <label>Alt Text</label>
                                <input type="text" class="cg-category-alt regular-text" 
                                       value="${category.alt || ''}"
                                       placeholder="Image alt text">
                            </div>
                        </div>
                    `;
                    selectedCategoriesContainer.appendChild(selectedItem);
                });

                // Show editor
                gridList.style.display = 'none';
                gridEditor.style.display = 'block';

            } catch (error) {
                console.error('Load error:', error);
                alert(`Failed to load grid: ${error.message}`);
            }
        }



// Handle Delete Grid - SINGLE VERSION
const deleteBtn = e.target.closest('.cg-delete-grid');
if (deleteBtn) {
    e.preventDefault();
    const gridId = deleteBtn.dataset.id;
    
    if (confirm('Are you sure you want to delete this grid?')) {
        try {
            const response = await fetch(cg_admin_vars.ajax_url, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'cg_delete_grid',
                    id: gridId,
                    nonce: cg_admin_vars.nonce
                })
            });
            
            const data = await response.json();
            if (data.success) {
                window.location.reload();
            } else {
                throw new Error(data.data || 'Delete failed');
            }
        } catch (error) {
            console.error('Delete error:', error);
            alert('Delete failed: ' + error.message);
        }
    }
}






      
    });


    // Media uploader
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('cg-upload-image')) {
            e.preventDefault();
            const button = e.target;
            const input = button.nextElementSibling;
            const preview = input.nextElementSibling;
            
            const frame = wp.media({
                title: 'Select Category Image',
                button: { text: 'Use this image' },
                multiple: false
            });
            
            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                input.value = attachment.url;
                preview.style.display = 'block';
                preview.querySelector('img').src = attachment.url;
            });
            
            frame.open();
        }
    });
    
    // Category selection
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('cg-add-category')) {
            const categoryItem = e.target.closest('li');
            const categoryId = categoryItem.dataset.id;
            const categoryName = categoryItem.querySelector('.cg-category-name').textContent;
            
            const selectedItem = document.createElement('li');
            selectedItem.dataset.id = categoryId;
            selectedItem.innerHTML = `
                <div class="cg-category-meta">
                    <span class="cg-category-name">${categoryName}</span>
                    <button class="button cg-remove-category">Remove</button>
                </div>
                <div class="cg-category-fields">
                    <div class="cg-form-group">
                        <label>Custom Image URL</label>
                        <button class="button cg-upload-image">Upload</button>
                        <input type="text" class="cg-category-image regular-text">
                        <div class="cg-image-preview" style="display:none;">
                            <img src="" style="max-width:100px;">
                        </div>
                    </div>
                    <div class="cg-form-group">
                        <label>Custom Link URL</label>
                        <input type="text" class="cg-category-link regular-text" 
                               placeholder="Leave blank for default category link">
                    </div>
                    <div class="cg-form-group">
                        <label>Alt Text</label>
                        <input type="text" class="cg-category-alt regular-text" 
                               placeholder="Image alt text">
                    </div>
                </div>
            `;
            
            document.getElementById('cg-selected-categories').appendChild(selectedItem);
        }
        
        if (e.target.classList.contains('cg-remove-category')) {
            e.target.closest('li').remove();
        }
    });
    
    // Save grid handler
    const saveButton = document.getElementById('cg-save-grid');
    if (saveButton) {
        saveButton.addEventListener('click', async function() {
            const saveButton = this;
            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';

            try {
                // Get settings data first
                const settings = {
                    desktop_columns: document.getElementById('cg-desktop-columns').value,
                    mobile_columns: document.getElementById('cg-mobile-columns').value,
                    carousel_mobile: document.getElementById('cg-carousel-mobile').checked,
                    image_size: document.getElementById('cg-image-size').value
                };

                // Get categories data
                const categories = Array.from(document.querySelectorAll('#cg-selected-categories li')).map(item => ({
                    id: item.dataset.id,
                    image: item.querySelector('.cg-category-image').value || '',
                    link: item.querySelector('.cg-category-link').value || '',
                    alt: item.querySelector('.cg-category-alt').value || ''
                }));

                const formData = new FormData();
                formData.append('action', 'cg_save_grid');
                formData.append('nonce', cg_admin_vars.nonce);
                formData.append('grid_id', document.querySelector('.cg-grid-editor').dataset.id || '');
                formData.append('name', document.getElementById('cg-grid-name').value);
                formData.append('slug', document.getElementById('cg-grid-slug').value);
                formData.append('categories', JSON.stringify(categories));
                formData.append('settings', JSON.stringify(settings));

                const response = await fetch(cg_admin_vars.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.data || 'Save failed');
                }

                alert('Grid saved successfully!');
                window.location.reload();

            } catch (error) {
                console.error('Save error:', error);
                alert(`Failed to save grid: ${error.message}`);
            } finally {
                saveButton.disabled = false;
                saveButton.textContent = 'Save Grid';
            }
        });
    }
    
    // Generate slug from name
    const nameInput = document.getElementById('cg-grid-name');
    if (nameInput) {
        nameInput.addEventListener('input', function() {
            const slugInput = document.getElementById('cg-grid-slug');
            if (!slugInput.value) {
                slugInput.value = this.value.toLowerCase()
                    .replace(/\s+/g, '-')
                    .replace(/[^\w\-]+/g, '')
                    .replace(/\-\-+/g, '-')
                    .replace(/^-+/, '')
                    .replace(/-+$/, '');
            }
        });
    }
});