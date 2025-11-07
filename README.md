# PM Leads (Scaffold v0.2.0)

Initial skeleton for PM Leads plugin.

## Included
- Custom post type: pm_job
- Role: pm_vendor
- Admin menu: Dashboard, Vendors, Jobs, Settings
- Options: price per lead, purchase limit, default radius, Google API key
- Shortcodes: [pm_leads_form], [pm_vendor_dashboard]
- Basic form handler that creates a pm_job and sets meta

## Setup
1. Upload `pm-leads` to `wp-content/plugins/`.
2. Activate "PM Leads" in Plugins.
3. Add a page with `[pm_leads_form]` for customers.
4. Add a page with `[pm_vendor_dashboard]` for vendors.
5. Visit PM Leads → Settings to set defaults.

## Next tasks
- Create hidden WooCommerce product when a job is created.
- Add radius match and vendor notifications.
- Build vendor dashboard UI for New Leads and Purchased Leads.
- Add credits and purchase logic with WooCommerce products.
