# TASA DayBook Shortcode Usage Guide

## Frontend Form Shortcode

The TASA DayBook plugin now includes a shortcode that allows users to submit their daily records from any page or post on your website, without needing to access the WordPress admin dashboard.

---

## Shortcode

```
[tasa_daybook_add_form]
```

---

## How to Use

### 1. **Create a New Page**
   - Go to **Pages → Add New** in your WordPress admin
   - Give it a title like "Submit Daily Record" or "DayBook Entry"

### 2. **Add the Shortcode**
   - In the page editor, add the shortcode:
     ```
     [tasa_daybook_add_form]
     ```
   - If using the Block Editor (Gutenberg), add a **Shortcode Block** first, then paste the shortcode inside

### 3. **Publish the Page**
   - Click **Publish**
   - Copy the page URL to share with your staff

### 4. **Share with Staff**
   - Give the page URL to your staff members
   - They can bookmark it for easy daily access

---

## Features

### ✅ **User Authentication**
- Only logged-in users can see and submit the form
- Non-logged-in visitors see a login prompt with a login button

### ✅ **One Submission Per Day**
- Each user can only submit one record per day
- After submission, they see a success message
- Attempting to submit again shows "Already Submitted" message

### ✅ **Live Difference Calculator**
- Real-time calculation as users type
- Shows the difference between expected and actual closing cash
- Color-coded: Green (surplus), Red (shortage), Black (exact match)

### ✅ **All Required Fields**
- Opening Cash
- Cash Sales
- Online Payments
- Cash Taken Out
- Closing Cash

### ✅ **Success/Error Messages**
- Clear feedback after form submission
- Success: "Record saved successfully!"
- Error: "A record for today already exists"

### ✅ **Mobile Responsive**
- Works perfectly on phones, tablets, and desktops
- Touch-friendly interface

---

## Example Page Setup

### Option 1: Simple Page
```
[tasa_daybook_add_form]
```

### Option 2: With Instructions
```
## Daily Cash Record Submission

Please submit your daily cash record before closing. All fields are required.

[tasa_daybook_add_form]

**Need help?** Contact your manager if you have any questions.
```

### Option 3: With Welcome Message
```
# Welcome to TASA DayBook

Hello! Please fill out today's cash record below.

[tasa_daybook_add_form]

---

**Important Notes:**
- Submit your record before leaving for the day
- Double-check all amounts before submitting
- You can only submit once per day
- Contact admin if you need to make corrections
```

---

## User Experience Flow

### For Logged-In Users (First Visit Today):
1. User visits the page
2. Sees the form with today's date
3. Fills in all cash amounts
4. Sees live difference calculation
5. Clicks "Submit Record"
6. Sees success message
7. Form is replaced with "Already Submitted" message

### For Logged-In Users (Already Submitted):
1. User visits the page
2. Sees "Already Submitted" message
3. Cannot submit again until tomorrow

### For Non-Logged-In Visitors:
1. User visits the page
2. Sees "Login Required" message
3. Clicks "Log In" button
4. Redirected to login page
5. After login, redirected back to the form

---

## Styling

The shortcode comes with its own beautiful styling that matches the admin interface:
- Clean, modern design
- Purple gradient accents
- Card-based layout
- Professional typography (Inter font)
- Smooth animations and transitions

The form automatically adapts to your theme's content width.

---

## Security Features

- ✅ WordPress nonce verification
- ✅ User authentication required
- ✅ Input sanitization and validation
- ✅ SQL injection protection
- ✅ XSS protection

---

## Admin vs Frontend

| Feature | Admin (Tools → TASA DayBook) | Frontend (Shortcode) |
|---------|------------------------------|----------------------|
| Add Record | ✅ Yes | ✅ Yes |
| View All Records | ✅ Yes | ❌ No |
| Edit Records | ✅ Admin Only | ❌ No |
| Delete Records | ✅ Admin Only | ❌ No |
| Access | WordPress Admin | Any Page/Post |

---

## Tips for Best Results

1. **Create a dedicated page** - Don't mix the shortcode with too much other content
2. **Add to menu** - Make it easy to find by adding the page to your navigation menu
3. **Bookmark for staff** - Have staff bookmark the page on their devices
4. **Mobile-first** - Test on mobile devices since staff may use phones/tablets
5. **Clear instructions** - Add brief instructions above the form if needed

---

## Troubleshooting

### Form doesn't appear
- Make sure you're logged in
- Check that the shortcode is spelled correctly: `[tasa_daybook_add_form]`

### "Already Submitted" message appears
- This is normal if you already submitted today
- You can only submit once per day
- Admins can edit records from the admin area

### Styling looks off
- Clear your browser cache
- Check if your theme has conflicting CSS
- The form should work with any WordPress theme

---

## Support

For issues or questions, contact your WordPress administrator.

