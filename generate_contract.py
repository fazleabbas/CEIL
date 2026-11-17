from docx import Document
from docx.shared import Pt, Inches, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn

doc = Document()

# -- Page margins --
for section in doc.sections:
    section.top_margin = Cm(2.5)
    section.bottom_margin = Cm(2.5)
    section.left_margin = Cm(3)
    section.right_margin = Cm(3)

style = doc.styles['Normal']
font = style.font
font.name = 'Calibri'
font.size = Pt(11)
font.color.rgb = RGBColor(0x1A, 0x1A, 0x1A)
style.paragraph_format.space_after = Pt(6)
style.paragraph_format.line_spacing = 1.15

# ── TITLE ──
title = doc.add_paragraph()
title.alignment = WD_ALIGN_PARAGRAPH.CENTER
run = title.add_run('SERVICE AGREEMENT CONTRACT')
run.bold = True
run.font.size = Pt(18)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

subtitle = doc.add_paragraph()
subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER
run = subtitle.add_run('Software System Service & Maintenance Agreement')
run.font.size = Pt(12)
run.font.color.rgb = RGBColor(0x4A, 0x4A, 0x4A)
run.italic = True

doc.add_paragraph()

p = doc.add_paragraph()
run = p.add_run('CONTRACT REFERENCE: ')
run.bold = True
run.font.size = Pt(10)
run = p.add_run('FAB/CEI/2026/001')
run.font.size = Pt(10)

p = doc.add_paragraph()
run = p.add_run('DATE OF AGREEMENT: ')
run.bold = True
run.font.size = Pt(10)
run = p.add_run('1st March, 2026')
run.font.size = Pt(10)

doc.add_paragraph()

# Horizontal line
p = doc.add_paragraph()
p.paragraph_format.space_after = Pt(2)
pPr = p._element.get_or_add_pPr()
pBdr = pPr.makeelement(qn('w:pBdr'), {})
bottom = pBdr.makeelement(qn('w:bottom'), {
    qn('w:val'): 'single',
    qn('w:sz'): '6',
    qn('w:space'): '1',
    qn('w:color'): '0B305E',
})
pBdr.append(bottom)
pPr.append(pBdr)

doc.add_paragraph()

# ── SECTION 1: PARTIES ──
h = doc.add_paragraph()
run = h.add_run('1. PARTIES TO THIS AGREEMENT')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

p = doc.add_paragraph()
p.paragraph_format.space_after = Pt(4)
run = p.add_run('This Service Agreement (\u201cAgreement\u201d) is entered into on ')
run = p.add_run('1st March, 2026')
run.bold = True
run = p.add_run(' by and between:')

p = doc.add_paragraph()
p.paragraph_format.left_indent = Cm(1)
run = p.add_run('THE SERVICE PROVIDER: ')
run.bold = True
run = p.add_run('Fabonic Limited')
run.bold = True
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)
run = p.add_run(' (hereinafter referred to as \u201cthe Provider\u201d)')

p = doc.add_paragraph()
p.paragraph_format.left_indent = Cm(1)
run = p.add_run('THE CLIENT: ')
run.bold = True
run = p.add_run('Central Electrical International Limited')
run.bold = True
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)
run = p.add_run(' (hereinafter referred to as \u201cthe Client\u201d)')

p = doc.add_paragraph()
p.add_run('Both parties are collectively referred to as \u201cthe Parties\u201d and individually as \u201ca Party.\u201d')

doc.add_paragraph()

# ── SECTION 2: SCOPE OF SERVICES ──
h = doc.add_paragraph()
run = h.add_run('2. SCOPE OF SERVICES')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

p = doc.add_paragraph('The Provider agrees to deliver and maintain the following software system and related services:')

services = [
    'Provision and maintenance of the Fabonic Information System (FIS) software application.',
    'System hosting, updates, bug fixes, and technical support for the duration of this Agreement.',
    'Unlimited user accounts \u2014 the Client may create and manage an unlimited number of user accounts within the system at no additional cost.',
    'Unlimited locations \u2014 the Client may configure and operate the system across an unlimited number of business locations at no additional cost.',
    'Remote technical support during standard business hours (Monday to Friday, 9:00 AM \u2013 5:00 PM EAT), and reasonable support outside these hours for critical issues.',
    'System enhancements and minor feature improvements as reasonably requested by the Client, subject to mutual agreement on scope and timelines.',
]

for s in services:
    p = doc.add_paragraph(s, style='List Bullet')
    p.paragraph_format.left_indent = Cm(1.5)
    p.paragraph_format.space_after = Pt(3)

doc.add_paragraph()

# ── SECTION 3: CONTRACT DURATION ──
h = doc.add_paragraph()
run = h.add_run('3. CONTRACT DURATION')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_3 = [
    ('3.1', 'This Agreement shall commence on 1st March, 2026 and shall remain in effect for a period of one (1) year, expiring on 28th February, 2027, unless terminated earlier in accordance with the terms herein.'),
    ('3.2', 'Upon expiry, this Agreement may be renewed for successive one-year terms by mutual written consent of both Parties, subject to revised terms if applicable.'),
]
for num, text in items_3:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 4: SERVICE FEES AND PAYMENT ──
h = doc.add_paragraph()
run = h.add_run('4. SERVICE FEES AND PAYMENT')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_4 = [
    ('4.1', 'The Client agrees to pay the Provider a monthly service fee of TZS 275,000/= (Tanzanian Shillings Two Hundred Seventy-Five Thousand Only) for the services described in this Agreement.'),
    ('4.2', 'The above fee covers unlimited users and unlimited locations as described in Section 2.'),
    ('4.3', 'Payment shall be made in advance, due on or before the 25th day of the current month for the upcoming month\u2019s service. The Provider shall issue an invoice to the Client accordingly.'),
    ('4.4', 'All payments shall be made via bank transfer or any other method mutually agreed upon by the Parties.'),
    ('4.5', 'In the event of late payment exceeding fifteen (15) days from the due date, the Provider reserves the right to suspend services until the outstanding amount is settled. A written notice of at least seven (7) days shall be given before any suspension of services.'),
    ('4.6', 'The service fee is subject to review upon renewal of this Agreement. Any changes to the fee structure shall be communicated in writing at least thirty (30) days prior to the renewal date.'),
    ('4.7', 'Any new customization, additional feature development, or modification to the system beyond the scope of standard maintenance and minor enhancements shall be quoted and charged separately based on the requirements and scope of work. The Provider shall provide a written quotation for approval by the Client before commencing any such work.'),
]
for num, text in items_4:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 5: HOSTING AND STORAGE ──
h = doc.add_paragraph()
run = h.add_run('5. HOSTING AND STORAGE')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_5 = [
    ('5.1', 'The Provider shall provide the Client with ten gigabytes (10 GB) of free hosting space as part of this Agreement. This allocation covers system data, uploaded files, and database storage.'),
    ('5.2', 'The Provider shall notify the Client when storage usage reaches eighty percent (80%) of the allocated 10 GB limit.'),
    ('5.3', 'Should the Client\u2019s storage usage exceed the allocated 10 GB, the Client will be required to upgrade their hosting account at an additional cost. The upgrade options and associated fees shall be communicated by the Provider at the time of the upgrade request.'),
    ('5.4', 'The Provider reserves the right to restrict uploads or certain system functionalities if the storage limit is exceeded and the Client has not upgraded their account within a reasonable period after being notified.'),
]
for num, text in items_5:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 6: DATABASE BACKUP ──
h = doc.add_paragraph()
run = h.add_run('6. DATABASE BACKUP AND DATA MANAGEMENT')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_6 = [
    ('6.1', 'Database backup shall be the sole responsibility of the Client. The system provides a built-in backup facility, and the Client is expected to perform regular backups using the designated feature within the application.'),
    ('6.2', 'The Provider shall not be held liable for any data loss arising from the Client\'s failure to perform regular backups.'),
    ('6.3', 'The Provider will ensure the backup functionality within the system remains operational and accessible at all times.'),
    ('6.4', 'The Client is strongly advised to maintain backup copies in a separate and secure location to safeguard against data loss due to hardware failure, theft, or other unforeseen events.'),
]
for num, text in items_6:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 7: OBLIGATIONS OF THE PROVIDER ──
h = doc.add_paragraph()
run = h.add_run('7. OBLIGATIONS OF THE PROVIDER')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

obligations_provider = [
    'Ensure the system is operational, stable, and available for the Client\'s use.',
    'Provide timely bug fixes, system updates, and security patches.',
    'Offer technical support as outlined in Section 2.',
    'Notify the Client in advance of any scheduled maintenance or downtime.',
    'Maintain confidentiality of all Client data in accordance with Section 9.',
    'Provide necessary training and documentation for system usage when reasonably requested.',
]
for o in obligations_provider:
    p = doc.add_paragraph(o, style='List Bullet')
    p.paragraph_format.left_indent = Cm(1.5)
    p.paragraph_format.space_after = Pt(3)

doc.add_paragraph()

# ── SECTION 8: OBLIGATIONS OF THE CLIENT ──
h = doc.add_paragraph()
run = h.add_run('8. OBLIGATIONS OF THE CLIENT')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

obligations_client = [
    'Make timely monthly payments in advance as stipulated in Section 4.',
    'Perform regular database backups as outlined in Section 6.',
    'Ensure that the system is used in accordance with its intended purpose and not for any unlawful activity.',
    'Provide the Provider with reasonable access to systems and information necessary for the performance of services.',
    'Report any system issues, bugs, or concerns to the Provider in a timely manner.',
    'Ensure that authorized personnel only access the system and maintain the security of login credentials.',
    'Upgrade the hosting account if storage usage exceeds the allocated 10 GB as outlined in Section 5.',
]
for o in obligations_client:
    p = doc.add_paragraph(o, style='List Bullet')
    p.paragraph_format.left_indent = Cm(1.5)
    p.paragraph_format.space_after = Pt(3)

doc.add_paragraph()

# ── SECTION 9: CONFIDENTIALITY ──
h = doc.add_paragraph()
run = h.add_run('9. CONFIDENTIALITY')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_9 = [
    ('9.1', 'Both Parties agree to keep confidential all proprietary information, trade secrets, business data, and any other sensitive information obtained during the course of this Agreement.'),
    ('9.2', 'The Provider shall not disclose, share, or sell any of the Client\'s data to any third party without prior written consent.'),
    ('9.3', 'The Client shall not reverse-engineer, decompile, or attempt to derive the source code of the software system.'),
    ('9.4', 'This confidentiality obligation shall survive the termination or expiry of this Agreement for a period of two (2) years.'),
]
for num, text in items_9:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 10: INTELLECTUAL PROPERTY ──
h = doc.add_paragraph()
run = h.add_run('10. INTELLECTUAL PROPERTY')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_10 = [
    ('10.1', 'The software system, including all source code, design, documentation, and related materials, remains the exclusive intellectual property of the Provider.'),
    ('10.2', 'This Agreement grants the Client a non-exclusive, non-transferable license to use the system for the duration of this Agreement only.'),
    ('10.3', 'The Client shall not copy, distribute, sublicense, or make the system available to any third party without written consent from the Provider.'),
]
for num, text in items_10:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 11: TERMINATION ──
h = doc.add_paragraph()
run = h.add_run('11. TERMINATION')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_11 = [
    ('11.1', 'Either Party may terminate this Agreement by providing one (1) month\u2019s written notice to the other Party.'),
    ('11.2', 'The Provider may terminate this Agreement immediately if the Client fails to make payment for two (2) consecutive months despite written reminders.'),
    ('11.3', 'The Client may terminate this Agreement immediately if the Provider fails to deliver the agreed services for a continuous period of thirty (30) days without valid justification.'),
    ('11.4', 'Upon termination, the Provider shall provide the Client with a final export of their data in a commonly used format within fourteen (14) days of the termination date, subject to settlement of all outstanding payments.'),
    ('11.5', 'All outstanding fees up to the date of termination shall remain payable.'),
]
for num, text in items_11:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 12: LIMITATION OF LIABILITY ──
h = doc.add_paragraph()
run = h.add_run('12. LIMITATION OF LIABILITY')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_12 = [
    ('12.1', 'The Provider shall not be liable for any indirect, incidental, or consequential damages arising from the use or inability to use the system, including but not limited to loss of profits, data, or business opportunities.'),
    ('12.2', 'The Provider\u2019s total liability under this Agreement shall not exceed the total fees paid by the Client in the three (3) months preceding the event giving rise to the claim.'),
    ('12.3', 'The Provider shall not be held responsible for any system failure or service interruption caused by factors beyond its reasonable control, including but not limited to internet connectivity issues, power outages, natural disasters, or acts of God (Force Majeure).'),
]
for num, text in items_12:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 13: DISPUTE RESOLUTION ──
h = doc.add_paragraph()
run = h.add_run('13. DISPUTE RESOLUTION')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_13 = [
    ('13.1', 'In the event of any dispute arising out of or in connection with this Agreement, the Parties shall first attempt to resolve the matter amicably through good-faith negotiation.'),
    ('13.2', 'If the dispute cannot be resolved through negotiation within thirty (30) days, either Party may refer the matter to mediation or arbitration in accordance with the laws of the United Republic of Tanzania.'),
    ('13.3', 'This Agreement shall be governed by and construed in accordance with the laws of the United Republic of Tanzania.'),
]
for num, text in items_13:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()

# ── SECTION 14: GENERAL PROVISIONS ──
h = doc.add_paragraph()
run = h.add_run('14. GENERAL PROVISIONS')
run.bold = True
run.font.size = Pt(13)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

items_14 = [
    ('14.1', 'Entire Agreement: This Agreement constitutes the entire understanding between the Parties and supersedes all prior negotiations, representations, or agreements relating to the subject matter herein.'),
    ('14.2', 'Amendments: No modification or amendment to this Agreement shall be valid unless made in writing and signed by both Parties.'),
    ('14.3', 'Severability: If any provision of this Agreement is found to be invalid or unenforceable, the remaining provisions shall continue in full force and effect.'),
    ('14.4', 'Waiver: The failure of either Party to enforce any right or provision of this Agreement shall not constitute a waiver of such right or provision.'),
    ('14.5', 'Assignment: Neither Party may assign or transfer its rights or obligations under this Agreement without the prior written consent of the other Party.'),
    ('14.6', 'Notices: All notices under this Agreement shall be in writing and delivered by hand, email, or registered post to the addresses provided by each Party.'),
]
for num, text in items_14:
    p = doc.add_paragraph()
    run = p.add_run(f'{num}  ')
    run.bold = True
    p.add_run(text)

doc.add_paragraph()
doc.add_paragraph()

# ── SIGNATURE BLOCK ──
p = doc.add_paragraph()
pPr = p._element.get_or_add_pPr()
pBdr = pPr.makeelement(qn('w:pBdr'), {})
bottom = pBdr.makeelement(qn('w:bottom'), {
    qn('w:val'): 'single',
    qn('w:sz'): '6',
    qn('w:space'): '1',
    qn('w:color'): '0B305E',
})
pBdr.append(bottom)
pPr.append(pBdr)

doc.add_paragraph()

h = doc.add_paragraph()
h.alignment = WD_ALIGN_PARAGRAPH.CENTER
run = h.add_run('SIGNATURES')
run.bold = True
run.font.size = Pt(14)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

p = doc.add_paragraph()
p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.add_run('IN WITNESS WHEREOF, the Parties have executed this Agreement as of the date first written above.')

doc.add_paragraph()
doc.add_paragraph()

# Signature table
table = doc.add_table(rows=6, cols=2)
table.alignment = WD_TABLE_ALIGNMENT.CENTER

for row in table.rows:
    for cell in row.cells:
        tc = cell._element
        tcPr = tc.get_or_add_tcPr()
        tcBorders = tcPr.makeelement(qn('w:tcBorders'), {})
        for border_name in ['top', 'left', 'bottom', 'right']:
            border = tcBorders.makeelement(qn(f'w:{border_name}'), {
                qn('w:val'): 'none',
                qn('w:sz'): '0',
                qn('w:space'): '0',
                qn('w:color'): 'auto',
            })
            tcBorders.append(border)
        tcPr.append(tcBorders)

cell = table.cell(0, 0)
p = cell.paragraphs[0]
run = p.add_run('FOR THE PROVIDER:')
run.bold = True
run.font.size = Pt(11)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

cell = table.cell(1, 0)
p = cell.paragraphs[0]
run = p.add_run('Fabonic Limited')
run.bold = True

cell = table.cell(2, 0)
cell.paragraphs[0].add_run('')

cell = table.cell(3, 0)
p = cell.paragraphs[0]
p.add_run('Signature: ______________________________')

cell = table.cell(4, 0)
p = cell.paragraphs[0]
p.add_run('Name:      ______________________________')

cell = table.cell(5, 0)
p = cell.paragraphs[0]
p.add_run('Date:        ______________________________')

cell = table.cell(0, 1)
p = cell.paragraphs[0]
run = p.add_run('FOR THE CLIENT:')
run.bold = True
run.font.size = Pt(11)
run.font.color.rgb = RGBColor(0x0B, 0x30, 0x5E)

cell = table.cell(1, 1)
p = cell.paragraphs[0]
run = p.add_run('Central Electrical International Limited')
run.bold = True

cell = table.cell(2, 1)
cell.paragraphs[0].add_run('')

cell = table.cell(3, 1)
p = cell.paragraphs[0]
p.add_run('Signature: ______________________________')

cell = table.cell(4, 1)
p = cell.paragraphs[0]
p.add_run('Name:      ______________________________')

cell = table.cell(5, 1)
p = cell.paragraphs[0]
p.add_run('Date:        ______________________________')

output_path = r'c:\xampp74\htdocs\simple_fis_sync_cei\Service_Agreement_Fabonic_CEI_2026.docx'
doc.save(output_path)
print(f'Contract saved to: {output_path}')
