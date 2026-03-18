
        const routeModal = document.getElementById('route-modal');
        const detailsModal = document.getElementById('details-modal');
        let tripEmployees = [];
        let tripExpenses = [];
        let tripCustomers = [];
        let editingId = null;
        let isDirty = false;

        function openModal() {
            editingId = null;
            isDirty = false;
            document.getElementById('modal-title').innerText = 'Authorize New Delivery';
            routeModal.classList.remove('hidden');
            tripEmployees = [];
            tripExpenses = [];
            tripCustomers = [];
            document.getElementById('assigned_staff').innerHTML = '';
            document.getElementById('expense_rows').innerHTML = '';
            document.getElementById('customer_blocks').innerHTML = '';
            addExpenseRow();
            addCustomerBlock();
        }

        function openEditModal(id) {
            editingId = id;
            isDirty = false;
            document.getElementById('modal-title').innerText = 'Edit Delivery #' + String(id).padStart(4, '0');
            
            fetch(`?action=view_delivery&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return alert(res.message);
                    const d = res.data;
                    
                    routeModal.classList.remove('hidden');
                    document.getElementById('delivery_date').value = d.delivery_date;
                    
                    // Clear Previous
                    tripEmployees = [];
                    document.getElementById('assigned_staff').innerHTML = '';
                    document.getElementById('expense_rows').innerHTML = '';
                    document.getElementById('customer_blocks').innerHTML = '';
                    
                    // Populate Staff
                    d.employees.forEach(e => addStaff(e.id || null, e.full_name, e.contact_number));
                    
                    // Populate Expenses
                    if (d.expenses.length > 0) d.expenses.forEach(e => addQuickExpense(e.expense_name, e.amount));
                    else addExpenseRow();
                    
                    // Populate Customers
                    d.customers.forEach(c => {
                        const blockId = addCustomerBlock(c.id);
                        selectCustomer(blockId, c.customer_id, c.name, c.contact_number);
                        
                        const block = document.getElementById(`cust-${blockId}`);
                        if (c.bill_image) {
                            block.dataset.existingBill = c.bill_image;
                            document.getElementById(`bill-label-${blockId}`).innerHTML = `
                                <div class="flex items-center gap-2 animate-[scaleIn_0.2s_ease]">
                                    <button type="button" onclick="document.getElementById('bill-input-${blockId}').click()" class="h-[36px] px-4 rounded-xl bg-indigo-600 text-white hover:bg-black transition-all flex items-center shadow-lg shadow-indigo-600/20 group">
                                        <i class="fa-solid fa-image mr-2 text-xs"></i>
                                        <span class="text-[9px] font-black uppercase tracking-widest">${c.bill_image.length > 15 ? c.bill_image.substring(0, 12) + '...' : c.bill_image}</span>
                                    </button>
                                    <div class="flex gap-1">
                                        <a href="../uploads/bills/${c.bill_image}" target="_blank" class="w-[36px] h-[36px] rounded-xl bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all flex items-center justify-center border border-indigo-100 shadow-sm group" title="View Full Bill Image">
                                            <i class="fa-solid fa-expand group-hover:scale-110 transition-transform text-xs"></i>
                                        </a>
                                        <button type="button" onclick="removeStoredBill('${blockId}')" class="w-[36px] h-[36px] rounded-xl bg-rose-50 text-rose-500 hover:bg-rose-600 hover:text-white transition-all flex items-center justify-center border border-rose-100 shadow-sm group" title="Remove Stored Bill">
                                            <i class="fa-solid fa-xmark group-hover:scale-110 transition-transform text-sm"></i>
                                        </button>
                                    </div>
                                </div>`;
                        }
                        
                        // Clear the auto-added empty row
                        const orderItemsDiv = block.querySelector('.order-items');
                        orderItemsDiv.innerHTML = ''; 
                        
                        c.items.forEach(item => {
                            const itemId = addItemRow(blockId);
                            const row = document.getElementById(`item-${itemId}`);
                            row.querySelector('.item-id').value = item.container_item_id;
                            row.querySelector('.item-search').value = item.brand_name;
                            row.querySelector('.item-qty').value = item.qty;
                            row.querySelector('.item-dmg').value = item.damaged_qty;
                            row.querySelector('.item-price').value = item.selling_price;
                            row.querySelector('.item-discount').value = item.discount_amount || 0; // Assuming discount_amount is available, default to 0
                            row.querySelector('.cost-price').value = item.cost_price;
                            
                            const stockDiv = row.querySelector('.stock-info');
                            if(stockDiv) {
                                const bgs = stockDiv.querySelectorAll('span');
                                if(bgs.length >= 2) { // Ensure both badges exist before updating
                                    bgs[0].innerHTML = `<i class="fa-solid fa-box-archive mr-1"></i> Stock: ${item.available_qty} PKTS`;
                                    bgs[1].innerHTML = `<i class="fa-solid fa-coins mr-1"></i> Unit Cost: LKR ${item.cost_price}`;
                                    stockDiv.classList.remove('hidden');
                                }
                            }
                        });
                    });
                    
                    calculateTotals();
                    
                    setTimeout(() => { isDirty = false; }, 100); // Reset dirty flag after automated populations
                });
        }

        function closeModal() {
            if (!routeModal.classList.contains('hidden')) {
                const hasItems = document.querySelectorAll('.order-items .grid').length > 0;
                const hasExpenses = document.querySelectorAll('#expense_rows .grid').length > 0;
                
                if (hasItems || hasExpenses) {
                    if (!confirm('You have unsaved changes in this delivery. Are you sure you want to discard them?')) {
                        return;
                    }
                }
            }
            routeModal.classList.add('hidden');
            detailsModal.classList.add('hidden');
        }

        function searchEmployees(term) {
            if(term.length < 2) return document.getElementById('emp_results').classList.add('hidden');
            fetch(`?action=search_employee&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(e => {
                            html += `<div class="p-3 hover:bg-indigo-50/50 cursor-pointer text-sm font-bold text-slate-700 flex items-center border-b border-white/5 transition-colors" onclick="addStaff(${e.id}, '${e.full_name}', '${e.contact_number}')">
                                <div class="w-7 h-7 bg-indigo-50 rounded-lg flex items-center justify-center mr-3 text-indigo-400 text-[10px] border border-indigo-100">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold leading-none mb-1">${e.full_name}</p>
                                    <p class="text-[9px] text-slate-400 font-medium tracking-wider">${e.contact_number}</p>
                                </div>
                            </div>`;
                        });
                    } else {
                        html = `
                            <div class="p-4 text-center">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 italic">Staff member not found</p>
                                <button type="button" onclick="openQuickEmployeeModal('${term}')" class="w-full bg-indigo-600 hover:bg-black text-white py-3 rounded-xl font-black text-[10px] uppercase tracking-widest transition-all shadow-lg">
                                    <i class="fa-solid fa-plus-circle mr-2"></i> Register "${term}"
                                </button>
                            </div>
                        `;
                    }
                    const res = document.getElementById('emp_results');
                    res.innerHTML = html;
                    res.classList.remove('hidden');
                });
        }

        function openQuickEmployeeModal(defaultName = '') {
            document.getElementById('quick_emp_name').value = defaultName;
            document.getElementById('quick-employee-modal').classList.remove('hidden');
        }

        function closeQuickModal() {
            document.getElementById('quick-employee-modal').classList.add('hidden');
        }

        function saveQuickEmployee(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'create_employee');

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        addStaff(res.id, res.name, formData.get('contact'));
                        closeQuickModal();
                        document.getElementById('quick-emp-form').reset();
                    } else {
                        alert(res.message);
                    }
                });
        }

        function addStaff(id, name, contact) {
            if(tripEmployees.some(e => e.id === id)) return;
            tripEmployees.push({id, name, contact});
            renderStaff();
            document.getElementById('emp_results').classList.add('hidden');
            document.getElementById('emp_search').value = '';
        }

        function removeStaff(id) {
            tripEmployees = tripEmployees.filter(e => e.id !== id);
            renderStaff();
        }

        function renderStaff() {
            const container = document.getElementById('assigned_staff');
            container.innerHTML = tripEmployees.map(e => `
                <div class="bg-white border border-slate-200 p-2 rounded-xl flex items-center gap-3 shadow-sm animate-[scaleIn_0.2s_ease]">
                    <div class="w-7 h-7 bg-slate-900 rounded-lg flex items-center justify-center text-white text-[10px]">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-800 leading-none mb-1">${e.name.toUpperCase()}</p>
                        <p class="text-[9px] font-bold text-slate-400 tracking-tighter">${e.contact}</p>
                    </div>
                    <button type="button" onclick="removeStaff(${e.id})" class="ml-1 text-slate-300 hover:text-rose-500 transition-colors">
                        <i class="fa-solid fa-circle-xmark text-sm"></i>
                    </button>
                </div>
            `).join('');
        }

        function addQuickExpense(name, amount = '') {
            const id = Date.now() + Math.random();
            const html = `
                <div id="exp-${id}" class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-3 justify-items-center md:items-center animate-[fadeIn_0.3s_ease] bg-slate-50/50 p-2 md:p-0 rounded-xl md:bg-transparent md:rounded-none">
                    <div class="col-span-1 md:col-span-8 w-full">
                        <input type="text" value="${name}" class="input-glass w-full h-[38px] exp-name text-xs font-bold">
                    </div>
                    <div class="col-span-1 md:col-span-3 w-full flex items-center gap-2">
                        <span class="md:hidden text-[10px] font-black uppercase text-slate-400">Amt:</span>
                        <input type="number" value="${amount}" placeholder="0.00" class="input-glass w-full h-[38px] exp-amt text-xs font-bold" onkeyup="calculateTotals()" autofocus>
                    </div>
                    <div class="col-span-1 md:col-span-1 w-full md:w-auto text-center font-bold text-slate-300 hover:text-rose-500 cursor-pointer bg-white md:bg-transparent rounded-lg py-2 md:py-0 border border-slate-100 md:border-0" onclick="document.getElementById('exp-${id}').remove(); calculateTotals();">
                        <i class="fa-solid fa-times text-xs"></i> <span class="md:hidden text-[10px] uppercase tracking-widest ml-1">Remove Expense</span>
                    </div>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        function addExpenseRow() {
            const id = Date.now();
            const html = `
                <div id="exp-${id}" class="grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-3 justify-items-center md:items-center animate-[fadeIn_0.3s_ease] bg-slate-50/50 p-2 md:p-0 rounded-xl md:bg-transparent md:rounded-none">
                    <div class="col-span-1 md:col-span-8 w-full">
                        <input type="text" placeholder="Expense description" class="input-glass w-full h-[38px] exp-name text-xs">
                    </div>
                    <div class="col-span-1 md:col-span-3 w-full flex items-center gap-2">
                        <span class="md:hidden text-[10px] font-black uppercase text-slate-400">Amt:</span>
                        <input type="number" placeholder="0.00" class="input-glass w-full h-[38px] exp-amt text-xs" onkeyup="calculateTotals()">
                    </div>
                    <div class="col-span-1 md:col-span-1 w-full md:w-auto text-center font-bold text-slate-300 hover:text-rose-500 cursor-pointer bg-white md:bg-transparent rounded-lg py-2 md:py-0 border border-slate-100 md:border-0" onclick="document.getElementById('exp-${id}').remove(); calculateTotals();">
                        <i class="fa-solid fa-times text-xs"></i> <span class="md:hidden text-[10px] uppercase tracking-widest ml-1">Remove Expense</span>
                    </div>
                </div>
            `;
            document.getElementById('expense_rows').insertAdjacentHTML('beforeend', html);
        }

        function addCustomerBlock(dcId = null) {
            const id = (Date.now() + Math.random()).toString().replace('.', '');
            const html = `
                <div id="cust-${id}" class="glass-card p-3 md:p-4 border border-slate-200 relative animate-[fadeIn_0.4s_ease] mb-4" data-dc-id="${dcId || ''}" data-customer-id="" data-existing-bill="">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                        <div class="flex items-center gap-3 flex-1 min-w-0 w-full">
                            <div class="w-10 h-10 bg-indigo-50 border-2 border-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-600 shadow-sm flex-shrink-0">
                                <i class="fa-solid fa-user-tag text-xs"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <input type="text" placeholder="Search or assign customer..." class="input-glass w-full h-[46px] text-sm font-bold cust-search" onkeyup="searchCustomers(this.value, '${id}')">
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2 flex-shrink-0 justify-end w-full md:w-auto">
                             <input type="file" class="hidden customer-bill-input" id="bill-input-${id}" onchange="handleBillSelect(this, '${id}')">
                             
                             <div id="bill-label-${id}" class="flex items-center">
                                 <button type="button" onclick="document.getElementById('bill-input-${id}').click()" title="Attach Bill" class="h-[46px] px-4 md:px-5 rounded-xl md:rounded-2xl bg-slate-900 text-white hover:bg-black transition-all flex items-center shadow-lg shadow-slate-900/10 group">
                                    <i class="fa-solid fa-file-invoice-dollar mr-2 group-hover:scale-110 transition-transform"></i>
                                    <span class="text-[10px] font-black uppercase tracking-widest">Upload Bill</span>
                                 </button>
                             </div>
                             
                             <button type="button" onclick="if(confirm('Are you sure you want to remove this customer and all their items from the delivery?')) { document.getElementById('cust-${id}').remove(); calculateTotals(); }" title="Remove Customer" class="w-[46px] h-[46px] rounded-xl md:rounded-2xl bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 transition-all border border-rose-100 flex items-center justify-center shadow-sm">
                                 <i class="fa-solid fa-trash-can text-sm"></i>
                             </button>
                        </div>
                    </div>
                    <div class="hidden customer-results absolute w-full left-0 top-[110px] md:top-[80px] z-30 bg-white/80 backdrop-blur-xl border border-white/40 rounded-2xl shadow-2xl p-2 max-w-md mx-6"></div>
                    
                    <div class="selected-customer-info mb-6 hidden bg-emerald-500/10 p-4 rounded-xl md:rounded-2xl border border-emerald-500/20 text-emerald-700 text-sm font-bold"></div>
                    
                    <div class="space-y-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-black text-slate-600 tracking-widest">Current Order Items</span>
                            <button type="button" onclick="addItemRow('${id}')" class="text-[10px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">+ Add Item</button>
                        </div>
                        
                        <div class="hidden md:grid grid-cols-12 gap-2 px-1 mb-1">
                            <div class="col-span-4"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Product</span></div>
                            <div class="col-span-1"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Qty</span></div>
                            <div class="col-span-2"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Selling</span></div>
                            <div class="col-span-2"><span class="text-[8px] uppercase font-black text-red-600 tracking-wider">Damaged</span></div>
                            <div class="col-span-2"><span class="text-[8px] uppercase font-black text-slate-400 tracking-wider">Discount</span></div>
                        </div>

                        <div class="order-items space-y-3 md:space-y-1"></div>
                        
                        <div class="pt-3 border-t border-slate-100 flex justify-between items-center">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Order Subtotal</span>
                            <span class="customer-subtotal text-sm md:text-base font-black text-slate-900">LKR 0.00</span>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('customer_blocks').insertAdjacentHTML('beforeend', html);
            addItemRow(id);
            return id;
        }

        function searchCustomers(term, blockId) {
            const block = document.getElementById(`cust-${blockId}`);
            const resultsDiv = block.querySelector('.customer-results');
            if(term.length < 2) return resultsDiv.classList.add('hidden');
            
            fetch(`?action=search_customer&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(c => {
                            html += `<div class="p-3 hover:bg-slate-50 cursor-pointer border-b border-white/5 last:border-0" onclick="selectCustomer(${blockId}, ${c.id}, '${c.name}', '${c.contact_number}')">
                                <p class="text-sm font-black text-slate-800 uppercase tracking-tight">${c.name}</p>
                                <p class="text-[10px] text-slate-500 uppercase font-black tracking-widest">${c.contact_number}</p>
                            </div>`;
                        });
                    } else {
                        html = `
                            <div class="p-4 text-center">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 italic">Client not found</p>
                                <button type="button" onclick="openQuickCustomerModal(${blockId}, '${term}')" class="w-full bg-emerald-600 hover:bg-black text-white py-3 rounded-xl font-black text-[10px] uppercase tracking-widest transition-all shadow-lg">
                                    <i class="fa-solid fa-plus-circle mr-2"></i> Register "${term}"
                                </button>
                            </div>
                        `;
                    }
                    resultsDiv.innerHTML = html;
                    resultsDiv.classList.remove('hidden');
                });
        }

        function openQuickCustomerModal(blockId, name) {
            document.getElementById('cust_block_id').value = blockId;
            document.getElementById('quick_cust_name').value = name;
            document.getElementById('quick-customer-modal').classList.remove('hidden');
        }

        function closeCustomerModal() {
            document.getElementById('quick-customer-modal').classList.add('hidden');
        }

        function saveQuickCustomer(e) {
            e.preventDefault();
            const blockId = document.getElementById('cust_block_id').value;
            const formData = new FormData(e.target);
            formData.append('action', 'create_customer');
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        selectCustomer(blockId, res.id, res.name, res.contact);
                        closeCustomerModal();
                        document.getElementById('quick-cust-form').reset();
                    } else { alert(res.message); }
                });
        }

        function selectCustomer(blockId, id, name, contact) {
            const block = document.getElementById(`cust-${blockId}`);
            block.dataset.customerId = id;
            const info = block.querySelector('.selected-customer-info');
            info.innerHTML = `<i class="fa-solid fa-check-circle mr-2"></i> Client Active: ${name} (${contact})`;
            info.classList.remove('hidden');
            block.querySelector('.customer-results').classList.add('hidden');
            block.querySelector('.cust-search').value = name;
        }

        function addItemRow(blockId) {
            const id = Date.now() + Math.random();
            const html = `
                <div id="item-${id}" class="space-y-1">
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-4 relative">
                            <input type="text" placeholder="Product..." class="input-glass w-full h-[36px] text-xs font-bold item-search" onkeyup="searchBrands(this.value, '${id}')">
                            <div class="brand-results hidden absolute w-full mt-1 bg-white/80 backdrop-blur-xl border border-white/40 rounded-xl shadow-2xl z-40 p-1"></div>
                            <input type="hidden" class="item-id">
                            <input type="hidden" class="cost-price">
                        </div>
                        <div class="col-span-1">
                            <input type="number" placeholder="0" class="input-glass w-full h-[36px] text-xs font-bold item-qty" onkeyup="calculateTotals()">
                        </div>
                        <div class="col-span-2">
                            <input type="number" placeholder="0.00" class="input-glass w-full h-[36px] text-xs font-bold item-price" onkeyup="calculateTotals()">
                        </div>
                        <div class="col-span-2">
                            <input type="number" placeholder="Dmg" class="input-glass w-full h-[36px] text-xs font-bold item-dmg border-red-100 text-red-600" onkeyup="calculateTotals()" title="Damaged Qty">
                        </div>
                        <div class="col-span-2">
                            <input type="number" placeholder="0.00" class="input-glass w-full h-[36px] text-xs font-bold item-discount" onkeyup="calculateTotals()" title="Discount">
                        </div>
                        <div class="col-span-1 text-center text-slate-300 hover:text-rose-500 cursor-pointer" onclick="document.getElementById('item-${id}').remove(); calculateTotals();">
                            <i class="fa-solid fa-minus-circle text-[10px]"></i>
                        </div>
                    </div>
                    <div class="stock-info px-2 hidden flex items-center gap-2">
                        <span class="text-[8px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-100/30"></span>
                        <span class="text-[8px] font-black text-amber-600 uppercase tracking-widest bg-amber-50 px-1.5 py-0.5 rounded border border-amber-100/30"></span>
                    </div>
                </div>
            `;
            document.getElementById(`cust-${blockId}`).querySelector('.order-items').insertAdjacentHTML('beforeend', html);
            return id;
        }

        function selectBrand(itemId, id, name, qty, cost) {
            const row = document.getElementById(`item-${itemId}`);
            row.querySelector('.item-id').value = id;
            row.querySelector('.item-search').value = `${name} (Avail: ${qty} / Cost: LKR ${cost})`;
            row.querySelector('.cost-price').value = cost;
            
            const stockDiv = row.querySelector('.stock-info');
            if(stockDiv) {
                const badges = stockDiv.querySelectorAll('span');
                if(badges.length >= 2) {
                    badges[0].innerHTML = `<i class="fa-solid fa-box-archive mr-1"></i> Stock: ${qty} PKTS`;
                    badges[1].innerHTML = `<i class="fa-solid fa-coins mr-1"></i> Unit Cost: LKR ${cost}`;
                    stockDiv.classList.remove('hidden');
                }
            }
            
            row.querySelector('.brand-results').classList.add('hidden');
            calculateTotals();
        }

        function searchBrands(term, itemId) {
            const row = document.getElementById(`item-${itemId}`);
            const resultsDiv = row.querySelector('.brand-results');
            if(term.length < 2) return resultsDiv.classList.add('hidden');

            fetch(`?action=search_brand_stock&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(b => {
                        html += `<div class="p-2 hover:bg-slate-50 cursor-pointer border-b border-slate-50 last:border-0" onclick="selectBrand('${itemId}', ${b.item_id}, '${b.brand_name}', ${b.available_qty}, ${b.per_item_cost})">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs font-black text-slate-800">${b.brand_name}</span>
                                <span class="text-[9px] bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded font-black">${b.available_qty} PKTS</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[8px] text-slate-400 uppercase font-bold bg-slate-100 px-1 rounded">${b.container_number}</span>
                                <span class="text-[8px] text-slate-400 uppercase font-bold bg-slate-100 px-1 rounded">${b.country}</span>
                                <span class="text-[8px] text-indigo-500 font-black ml-auto">LKR ${b.per_item_cost} / UNIT</span>
                            </div>
                        </div>`;
                    });
                    resultsDiv.innerHTML = html || '<p class="p-3 text-[9px] text-slate-400 text-center font-black">OUT OF STOCK</p>';
                    resultsDiv.classList.remove('hidden');
                });
        }

        function handleBillSelect(input, id) {
            const lbl = document.getElementById(`bill-label-${id}`);
            if (input.files && input.files[0]) {
                const url = URL.createObjectURL(input.files[0]);
                lbl.innerHTML = `
                    <div class="flex items-center gap-2 animate-[scaleIn_0.2s_ease]">
                        <button type="button" onclick="document.getElementById('bill-input-${id}').click()" class="h-[46px] px-5 rounded-2xl bg-emerald-500 text-white hover:bg-emerald-600 transition-all flex items-center shadow-lg shadow-emerald-500/20 group">
                            <i class="fa-solid fa-circle-check mr-2"></i>
                            <span class="text-[10px] font-black uppercase tracking-widest">Bill Attached</span>
                        </button>
                        <div class="flex gap-1">
                            <a href="${url}" target="_blank" class="w-[46px] h-[46px] rounded-2xl bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all flex items-center justify-center border border-indigo-100 shadow-sm group" title="Preview Bill">
                                <i class="fa-solid fa-eye group-hover:scale-110 transition-transform"></i>
                            </a>
                            <button type="button" onclick="removeNewBill('${id}')" class="w-[46px] h-[46px] rounded-2xl bg-rose-50 text-rose-500 hover:bg-rose-600 hover:text-white transition-all flex items-center justify-center border border-rose-100 shadow-sm group" title="Remove Bill">
                                <i class="fa-solid fa-xmark group-hover:scale-110 transition-transform text-lg"></i>
                            </button>
                        </div>
                    </div>`;
            }
        }

        function removeNewBill(id) {
            if(!confirm("Remove newly attached bill image?")) return;
            const input = document.getElementById(`bill-input-${id}`);
            input.value = '';
            resetBillLabel(id);
            isDirty = true;
        }

        function removeStoredBill(id) {
            if(!confirm("Remove stored bill image from this customer?")) return;
            const block = document.getElementById(`cust-${id}`);
            block.dataset.existingBill = ''; // Clear the dataset so it isn't sent back to the server
            // Clear the file input just in case
            document.getElementById(`bill-input-${id}`).value = '';
            resetBillLabel(id);
            isDirty = true;
        }

        function resetBillLabel(id) {
            const lbl = document.getElementById(`bill-label-${id}`);
            lbl.innerHTML = `
                <button type="button" onclick="document.getElementById('bill-input-${id}').click()" title="Attach Bill" class="h-[46px] px-5 rounded-2xl bg-slate-900 text-white hover:bg-black transition-all flex items-center shadow-lg shadow-slate-900/10 group animate-[scaleIn_0.2s_ease]">
                   <i class="fa-solid fa-file-invoice-dollar mr-2 group-hover:scale-110 transition-transform"></i>
                   <span class="text-[10px] font-black uppercase tracking-widest">Upload Bill</span>
                </button>
            `;
        }

        function calculateTotals() {
            let totalExp = 0;
            document.querySelectorAll('.exp-amt').forEach(i => totalExp += (parseFloat(i.value) || 0));
            document.getElementById('total_expenses_display').innerText = `LKR ${totalExp.toLocaleString()}`;

            let totalRev = 0;
            let totalCost = 0;
            
            document.querySelectorAll('#customer_blocks .glass-card').forEach(block => {
                let customerSubtotal = 0;
                block.querySelectorAll('.order-items .grid').forEach(row => {
                    const q = parseFloat(row.querySelector('.item-qty').value) || 0;
                    const p = parseFloat(row.querySelector('.item-price').value) || 0;
                    const cp = parseFloat(row.querySelector('.cost-price').value) || 0;
                    const dmg = parseFloat(row.querySelector('.item-dmg').value) || 0;
                    const disc = parseFloat(row.querySelector('.item-discount').value) || 0;
                    
                    const lineTotal = ((q - dmg) * p) - disc;
                    customerSubtotal += lineTotal;
                    totalRev += lineTotal;
                    totalCost += (q * cp);
                });
                const subtotalEl = block.querySelector('.customer-subtotal');
                if(subtotalEl) subtotalEl.innerText = `LKR ${customerSubtotal.toLocaleString()}`;
            });
            
            const estProfit = totalRev - totalCost - totalExp;
            document.getElementById('total_sales_display').innerText = `LKR ${totalRev.toLocaleString()}`;
            document.getElementById('total_profit_display').innerText = `LKR ${estProfit.toLocaleString()}`;
            
            // Color coding for profit
            const profitEl = document.getElementById('total_profit_display');
            if(estProfit < 0) profitEl.classList.replace('text-indigo-600', 'text-rose-600');
            else profitEl.classList.replace('text-rose-600', 'text-indigo-600');
        }

        function processRouteSave() {
            const btn = event.currentTarget;
            const originalHtml = btn.innerHTML;
            
            const formData = new FormData();
            
            // Collect Data
            const date = document.getElementById('delivery_date').value;
            const emps = tripEmployees.map(e => e.id);
            
            const exps = [];
            document.querySelectorAll('#expense_rows .grid').forEach(r => {
                const n = r.querySelector('.exp-name').value;
                const a = r.querySelector('.exp-amt').value;
                if(n && a) exps.push({name: n, amount: a});
            });

            const custs = [];
            document.querySelectorAll('#customer_blocks .glass-card').forEach((b, index) => {
                const dcId = b.dataset.dcId;
                const cid = b.dataset.customerId;
                const items = [];
                b.querySelectorAll('.order-items .grid').forEach(r => {
                    const iid = r.querySelector('.item-id').value;
                    const q = r.querySelector('.item-qty').value;
                    const p = r.querySelector('.item-price').value;
                    const cp = r.querySelector('.cost-price').value;
                    const dmg = r.querySelector('.item-dmg').value;
                    const disc = r.querySelector('.item-discount').value;
                    if(iid && q && p) items.push({item_id: iid, qty: q, selling_price: p, cost_price: cp, damaged_qty: dmg, discount: disc});
                });
                
                const billInput = b.querySelector('.customer-bill-input');
                const existingBill = b.dataset.existingBill;
                
                if(cid && items.length) {
                    custs.push({dc_id: dcId, customer_id: cid, items, existing_bill: existingBill});
                    if(billInput && billInput.files[0]) {
                        formData.append(`bill_${index}`, billInput.files[0]);
                    }
                }
            });

            if(!emps.length || !custs.length) return alert('Assign at least one staff and one customer order.');

            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            formData.append('action', 'save_delivery');
            formData.append('delivery_date', date);
            if (editingId) formData.append('editing_id', editingId);
            formData.append('employees', JSON.stringify(emps));
            formData.append('expenses', JSON.stringify(exps));
            formData.append('customers', JSON.stringify(custs));

            fetch('nwdelivery.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) location.reload();
                    else {
                        alert(res.message);
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }
                });
        }

        function openPaymentsModal(id) {
            document.getElementById('payments-modal-subtitle').innerText = 'Trip #TRP-' + String(id).padStart(4, '0');
            fetch(`?action=view_delivery&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.success) return alert(res.message);
                    const d = res.data;
                    const container = document.getElementById('payments-modal-content');
                    
                    let html = '';
                    d.customers.forEach(c => {
                        const total = parseFloat(c.subtotal) - parseFloat(c.discount);
                        const paid = parseFloat(c.total_paid);
                        const pending = total - paid;
                        
                        html += `
                            <div class="glass-card p-6 border-slate-200/50 bg-white/40">
                                <div class="flex flex-col md:flex-row md:items-center gap-8 mb-8">
                                    <div class="flex items-center gap-4 min-w-[240px]">
                                        <div class="w-12 h-12 bg-indigo-50 border-2 border-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-600 shadow-sm flex-shrink-0">
                                            <i class="fa-solid fa-store text-lg"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-xl font-black text-slate-900 font-['Outfit'] tracking-tight">${c.name}</h4>
                                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">${c.address}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex gap-12 border-l border-slate-100 pl-8">
                                        <div class="text-left">
                                            <p class="text-[9px] uppercase font-black text-slate-400 mb-1">Total Bill</p>
                                            <p class="text-sm font-black text-slate-900">LKR ${total.toLocaleString()}</p>
                                        </div>
                                        <div class="text-left border-l border-slate-100 pl-8">
                                            <p class="text-[9px] uppercase font-black text-emerald-500 mb-1">Paid</p>
                                            <p class="text-sm font-black text-emerald-600">LKR ${paid.toLocaleString()}</p>
                                        </div>
                                        <div class="text-left border-l border-slate-100 pl-8">
                                            <p class="text-[9px] uppercase font-black text-rose-500 mb-1">Pending</p>
                                            <p class="text-sm font-black text-rose-600">LKR ${pending.toLocaleString()}</p>
                                        </div>
                                    </div>
                                    
                                    <div class="md:ml-auto">
                                        <button onclick="openAddPayment(${c.id}, '${c.name.replace(/'/g, "\\'")}', ${pending}, ${c.customer_id})" class="bg-indigo-600 hover:bg-black text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-2xl shadow-indigo-600/20 transition-all flex items-center gap-3 group">
                                            <i class="fa-solid fa-plus-circle group-hover:rotate-90 transition-transform"></i>
                                            <span>New Payment</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="border-t border-slate-100 pt-5">
                                    <h5 class="text-[9px] uppercase font-black text-slate-400 tracking-[0.2em] mb-4">Transaction History</h5>
                                    ${c.payments.length ? `
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-left">
                                                <thead>
                                                    <tr class="text-[9px] uppercase font-black text-slate-400 border-b border-slate-100">
                                                        <th class="pb-3 px-2">Type</th>
                                                        <th class="pb-3 px-2">Date</th>
                                                        <th class="pb-3 px-2">Bank Details</th>
                                                        <th class="pb-3 px-2 text-center">Proof</th>
                                                        <th class="pb-3 px-2 text-right">Total</th>
                                                        <th class="pb-3 px-2 text-center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-50">
                                                    ${c.payments.map(p => `
                                                        <tr class="text-[11px] font-bold text-slate-700 hover:bg-slate-50/50 transition-colors">
                                                            <td class="py-3 px-2">
                                                                <span class="bg-indigo-50 text-indigo-700 px-2 py-1 rounded text-[9px] uppercase font-black">${p.payment_type}</span>
                                                            </td>
                                                            <td class="py-3 px-2 text-slate-500">${new Date(p.payment_date).toLocaleDateString()}</td>
                                                            <td class="py-3 px-2">
                                                                ${p.bank_name ? `
                                                                    <p class="text-slate-900 leading-none mb-1">${p.bank_name}</p>
                                                                    <p class="text-[9px] text-slate-400 font-bold uppercase">${p.bank_acc}</p>
                                                                ` : '<span class="text-slate-300 italic font-medium">N/A</span>'}
                                                                ${p.cheque_payer ? `<p class="text-[9px] text-indigo-500 font-black mt-1 uppercase tracking-tighter">Payer: ${p.cheque_payer}</p>` : ''}
                                                            </td>
                                                            <td class="py-3 px-2 text-center">
                                                                ${p.proof_image ? `
                                                                    <a href="../uploads/payments/${p.proof_image}" target="_blank" class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center border border-indigo-100 shadow-sm" title="View Proof">
                                                                        <i class="fa-solid fa-image text-xs"></i>
                                                                    </a>
                                                                ` : '<span class="text-[9px] text-slate-300 font-bold uppercase">N/A</span>'}
                                                            </td>
                                                            <td class="py-3 px-2 text-right font-black text-slate-900 leading-tight">LKR ${parseFloat(p.amount).toLocaleString()}</td>
                                                            <td class="py-3 px-2 text-center">
                                                                <button onclick="deletePayment(${p.id})" class="text-rose-400 hover:text-rose-600 transition-all">
                                                                    <i class="fa-solid fa-trash-can"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<p class="text-[10px] font-bold text-slate-400 italic py-2 text-center">No transactions recorded for this customer.</p>'}
                                </div>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                    document.getElementById('payments-modal').classList.remove('hidden');
                });
        }

        let currentCustomerInfo = { id: null, name: '' };
        function openAddPayment(dcId, name, pending, custId) {
            currentCustomerInfo = { id: custId, name: name };
            document.getElementById('payment_dc_id').value = dcId;
            document.getElementById('add-payment-cust-name').innerText = name;
            document.getElementById('payment_amount').value = pending;
            document.getElementById('add-payment-modal').classList.remove('hidden');
            togglePaymentFields();
        }

        function togglePaymentFields() {
            const type = document.getElementById('payment_type').value;
            document.getElementById('bank_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            document.getElementById('cheque_section').classList.toggle('hidden', type !== 'Cheque');
            document.getElementById('proof_section').classList.toggle('hidden', type !== 'Account Transfer' && type !== 'Cheque');
            
            if (type === 'Cheque' && currentCustomerInfo.id) {
                document.getElementById('selected_chq_cust_id').value = currentCustomerInfo.id;
                document.getElementById('chq_cust_search').value = currentCustomerInfo.name;
            }
        }

        function searchBanks(term) {
            if(term.length < 2) return document.getElementById('bank_results').classList.add('hidden');
            fetch(`?action=search_bank&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if(data.length) {
                        data.forEach(b => {
                            html += `<div class="p-3 hover:bg-indigo-50 cursor-pointer border-b border-slate-50 last:border-0" onclick="selectBank(${b.id}, '${b.name}', '${b.account_number}')">
                                <p class="text-xs font-black text-slate-800">${b.name}</p>
                                <p class="text-[9px] text-slate-400 font-bold uppercase">${b.account_number} &bull; ${b.account_name}</p>
                            </div>`;
                        });
                    } else {
                        html = `<div class="p-3 text-center text-[10px] font-bold text-slate-400 italic">Account not found.</div>`;
                    }
                    const res = document.getElementById('bank_results');
                    res.innerHTML = html;
                    res.classList.remove('hidden');
                });
        }

        function selectBank(id, name, accNo) {
            document.getElementById('selected_bank_id').value = id;
            document.getElementById('bank_search').value = name + ' (' + accNo + ')';
            document.getElementById('bank_results').classList.add('hidden');
        }

        function openNewBankModal() {
            document.getElementById('new-bank-modal').classList.remove('hidden');
        }

        function saveNewBank(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'create_bank');
            fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        const accNo = e.target.querySelector('[name="acc_no"]').value;
                        selectBank(res.id, res.name, accNo);
                        closeModal('new-bank-modal');
                    }
                });
        }

        function searchChequeCustomers(term) {
            if(term.length < 2) return document.getElementById('chq_cust_results').classList.add('hidden');
            fetch(`?action=search_cheque_customer&term=${term}`)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    data.forEach(c => {
                        html += `<div class="p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50 last:border-0" onclick="selectChqCust(${c.id}, '${c.name.replace(/'/g, "\\'")}')">
                            <p class="text-xs font-black text-slate-800 uppercase">${c.name}</p>
                        </div>`;
                    });
                    const res = document.getElementById('chq_cust_results');
                    res.innerHTML = html || '<div class="p-3 text-center text-[10px] text-slate-400 font-bold italic">No results</div>';
                    res.classList.remove('hidden');
                });
        }

        function selectChqCust(id, name) {
            document.getElementById('selected_chq_cust_id').value = id;
            document.getElementById('chq_cust_search').value = name;
            document.getElementById('chq_cust_results').classList.add('hidden');
        }

        function savePayment(e) {
            e.preventDefault();
            const btn = e.submitter;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const fd = new FormData(e.target);
            fd.append('action', 'save_payment');

            fetch('nwdelivery.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        location.reload();
                    } else {
                        alert(res.message);
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }
                });
        }

        // Updated closeModal to support generic modal IDs
        function closeModal(id) {
            if (id) {
                document.getElementById(id).classList.add('hidden');
                return;
            }
            // Check dirty flag for route-modal
            if (!routeModal.classList.contains('hidden') && isDirty) {
                if (!confirm('You have unsaved changes in this delivery. Are you sure you want to discard them?')) {
                    return;
                }
            }
            routeModal.classList.add('hidden');
            detailsModal.classList.add('hidden');
            isDirty = false;
        }

        function confirmDeleteTrip(id) {
            if(confirm(`Are you sure you want to PERMANENTLY DELETE Delivery #DEL-${id}? All associated data and stocks will be affected.`)) {
                fetch('nwdelivery.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=delete_delivery&id=${id}`
                }).then(r => r.json()).then(res => {
                    if(res.success) location.reload();
                    else alert(res.message);
                });
            }
        }
        function deletePayment(id) {
            const reason = prompt("Please provide a reason for deleting this payment:");
            if (reason === null) return; // Cancelled
            
            if (confirm("Are you sure you want to delete this payment record? This action will be logged in the ledger.")) {
                const fd = new FormData();
                fd.append('action', 'delete_payment');
                fd.append('id', id);
                fd.append('reason', reason);
                
                fetch('nwdelivery.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if(res.success) location.reload();
                        else alert(res.message);
                    });
            }
        }

        // Track changes globally
        document.getElementById('route-form').addEventListener('input', () => { isDirty = true; });
        document.getElementById('route-form').addEventListener('change', () => { isDirty = true; });
    