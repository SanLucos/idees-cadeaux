package fr.frigologie.ideescadeaux;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        asShareLink(getIntent());
        super.onCreate(savedInstanceState);
    }

    @Override
    protected void onNewIntent(Intent intent) {
        asShareLink(intent);
        super.onNewIntent(intent);
    }

    /**
     * Spec §5.6: a text shared from another app (ACTION_SEND) becomes a
     * "<application id>://share?text=…&title=…" link, which the web layer
     * already handles for iOS (services/shareIntake.ts) through the App
     * plugin's appUrlOpen / getLaunchUrl. The scheme is the application
     * id, never hard-coded (CLAUDE.md §8).
     */
    private void asShareLink(Intent intent) {
        if (intent == null || !Intent.ACTION_SEND.equals(intent.getAction())) {
            return;
        }
        String type = intent.getType();
        if (type == null || !type.startsWith("text/")) {
            return;
        }

        Uri.Builder link = new Uri.Builder().scheme(getPackageName()).authority("share");
        CharSequence text = intent.getCharSequenceExtra(Intent.EXTRA_TEXT);
        if (text != null) {
            link.appendQueryParameter("text", text.toString());
        }
        String subject = intent.getStringExtra(Intent.EXTRA_SUBJECT);
        if (subject != null) {
            link.appendQueryParameter("title", subject);
        }

        intent.setAction(Intent.ACTION_VIEW);
        intent.setData(link.build());
    }
}
