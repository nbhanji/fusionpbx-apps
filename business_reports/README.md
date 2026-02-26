# FusionPBX Business Reports

Business-grade call reporting with saved views and dynamic filtering for FusionPBX's v_xml_cdr table.

## Features

- **Saved Views**: Create and save custom report configurations
- **Call Type Classification**: Automatic classification of Inbound, Outbound, and Local (Internal) calls using v_xml_cdr's direction field
- **Dynamic Metrics**: 
  - Total Calls (Dial/Attempts)
  - Connected Calls
  - Not Connected Calls
  - Talk Time
  - ASR (Answer Seizure Ratio)
  - ACD (Average Call Duration)
  - Average Ring Time
  - Breakdown by hangup cause
- **Flexible Grouping**: Group by day/hour, extension, DID, gateway, or hangup cause
- **Dynamic Filters**: Filter by date range, call type, extension, gateway, and more
- **CSV Export**: Export report results to CSV format
- **Performance Safeguards**: Query optimization and result limits

## Installation

1. Copy the `business_reports` directory to your FusionPBX apps directory:
   ```
   /var/www/fusionpbx/app/business_reports/
   ```

2. Navigate to: **Advanced > Upgrade** in FusionPBX web interface

3. Click **Schema** to create the database tables

4. Click **Permissions** to set up permissions

5. Click **Menu** to add the menu entries

6. Clear cache: **Advanced > Flush Cache**

## Usage

### Creating a Report

1. Navigate to: **Reports > Business Reports**
2. Click **New Report**
3. Configure:
   - Report Name and Description
   - Call Type (Inbound/Outbound/Local/All)
   - Date Range (last N days)
   - Grouping (by time and/or dimension)
   - Metrics to include
   - Sort order and result limit
4. Click **Create Report** to save

### Running a Report

1. From the dashboard, click on any saved report
2. The report will execute and display results in a table
3. Click **Export CSV** to download results

### Editing a Report

1. Click the Edit button next to any report
2. Modify the configuration
3. Click **Update Report** to save changes

## Call Type Classification

The system uses v_xml_cdr's built-in `direction` field for call classification:
- **Inbound**: `direction = 'inbound'`
- **Outbound**: `direction = 'outbound'`
- **Local**: `direction IN ('local', 'internal')`

## Security & Multi-Tenancy

- All queries are automatically filtered by `domain_uuid`
- Users can only access reports within their domain
- Permissions:
  - `business_report_view`: View reports
  - `business_report_add`: Create new reports
  - `business_report_edit`: Edit reports
  - `business_report_delete`: Delete reports

## Performance Considerations

### Query Limits

- Default date range: Last 7 days
- Maximum date range: 90 days (configurable in view builder)
- Maximum result rows: 10,000 (hard cap)
- For large date ranges, use time grouping (by day/hour)

### Recommended Indexes

```sql
CREATE INDEX idx_xml_cdr_domain_start ON v_xml_cdr (domain_uuid, start_stamp);
CREATE INDEX idx_xml_cdr_domain_start_billsec ON v_xml_cdr (domain_uuid, start_stamp, billsec);
```

## Troubleshooting

### No Results

1. Verify date range includes data
2. Check call type classification filter
3. Review other filters - they may be too restrictive

### Slow Queries

1. Ensure recommended indexes are created (see above)
2. Reduce date range
3. Use time grouping for large date ranges
4. Limit the number of metrics

## License

Mozilla Public License 1.1 (MPL 1.1)

## Credits

Developed for FusionPBX
