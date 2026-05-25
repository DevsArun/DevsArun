-- ============================================================
-- WhatsApp CRM - Seed Data (Sample from Patna Toy Shops CSV)
-- For testing purposes
-- ============================================================

USE `whatsapp_crm`;

INSERT INTO `leads` (`business_name`, `address`, `locality`, `city`, `state`, `phone_raw`, `phone_clean`, `website_url`, `website_status`, `rating`, `review_count`, `business_status`, `pitch_type`, `language_preference`, `whatsapp_status`, `outreach_status`) VALUES
('The Marvel Toys', 'UNIVERSAL TOWER, B-7, More, near DOMINO''S, Kurji, Patna, Bihar 800010, India', 'Kurji', 'Patna', 'Bihar', '+91 70046 67347', '917004667347', 'http://themarveltoys.in/', 'has_website', 4.9, 592, 'Open', 'A', 'hinglish', 'pending', 'pending'),
('Kiran Toys', 'Shanta Complex, Rajeshwar, West Boring Canal Rd, Anandpuri, Patna, Bihar 800001, India', 'Anandpuri', 'Patna', 'Bihar', '+91 80830 32988', '918083032988', NULL, 'no_website', 4.9, 554, 'Open', 'B', 'hinglish', 'pending', 'pending'),
('Patna Toys', 'Patliputra Kurji Pnm, Mall Rd, near Sai Mandir Road, New Patliputra Colony, Patna, Bihar 800013, India', 'New Patliputra Colony', 'Patna', 'Bihar', '+91 78706 36168', '917870636168', 'https://www.patnatoys.com/', 'has_website', 4.7, 335, 'Open', 'A', 'hinglish', 'pending', 'pending'),
('Toys shop', 'Maurya complex, Shop no 15, Maurya Tower, Patna, Bihar 800001, India', 'Maurya Tower', 'Patna', 'Bihar', '+91 98354 38803', '919835438803', NULL, 'no_website', 4.2, 32, 'Open', 'B', 'hinglish', 'pending', 'pending'),
('THE TOY LAND', 'Basement, Hari lal Building, Bailey Rd, RPS More, Cantt, Danapur, Patna, Bihar 801503, India', 'RPS More', 'Patna', 'Bihar', '+91 94300 97086', '919430097086', 'http://thetoyland.in/', 'has_website', 4.8, 90, 'Open', 'A', 'hinglish', 'pending', 'pending'),
('ToyKart', 'Pillar no2, Chandan Deep Apartment, Jagdeo Path, near Allan Solly, Jagdeo Path, Raja Bazar, Dhanaut, Patna, Bihar 800014, India', 'Jagdeo Path', 'Patna', 'Bihar', '+91 92969 61359', '919296961359', NULL, 'no_website', 4.9, 57, 'Open', 'B', 'hinglish', 'pending', 'pending'),
('KIDS BATTERY THAR', 'Nutan Plaza, kids thaar, above bonkers cafe, near 9to9 super market, Bander Bagicha, Patna, Bihar 800001, India', 'Bander Bagicha', 'Patna', 'Bihar', '+91 74848 97134', '917484897134', 'https://kidsthaar.in/', 'has_website', 4.9, 165, 'Open', 'A', 'hinglish', 'pending', 'pending'),
('MINISO PATNA', 'No M2/7, Boring Rd, opposite Jamuna Apartment, Sri Krishna Puri, Patna, Bihar 800001, India', 'Sri Krishna Puri', 'Patna', 'Bihar', '+91 91993 17179', '919199317179', NULL, 'no_website', 4.2, 780, 'Open', 'B', 'hinglish', 'pending', 'pending');

-- Sample campaign
INSERT INTO `campaigns` (`campaign_name`, `total_leads`, `status`) VALUES
('Patna Toy Shops - Batch 1', 44, 'paused');
